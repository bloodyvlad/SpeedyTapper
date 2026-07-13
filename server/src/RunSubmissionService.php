<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class RunSubmissionService
{
    public function __construct(
        private readonly PDO $database,
        private readonly LeaderboardRepository $leaderboard,
    ) {
    }

    public function submit(string $playerId, ScoreSubmission $score): array
    {
        $record = $this->record($playerId, $score, true);
        $payload = $this->leaderboard->payload($score->mode, $playerId);
        $payload['rank'] = $payload['playerRank'];
        $payload['improved'] = $record['improved'];
        $payload['duplicate'] = $record['duplicate'];
        $payload['coinsEarned'] = $record['coinsEarned'];
        $payload['coinBalance'] = $record['coinBalance'];
        $payload['totalPlayMs'] = $record['totalPlayMs'];
        return $payload;
    }

    private function record(string $playerId, ScoreSubmission $score, bool $mayRetry): array
    {
        $this->database->beginTransaction();
        try {
            $player = $this->lockPlayer($playerId);
            $existing = $this->findCompletedRun($score->runId);
            if (is_array($existing)) {
                $this->assertMatchingRun($existing, $playerId, $score);
                $this->database->commit();
                return [
                    'improved' => (bool) $existing['leaderboard_improved'],
                    'duplicate' => true,
                    'coinsEarned' => (int) $existing['coins_awarded'],
                    'coinBalance' => (int) $player['coins'],
                    'totalPlayMs' => (int) $player['total_play_ms'],
                ];
            }

            $progression = CoinProgression::accrue(
                (int) $player['coin_time_remainder_ms'],
                $score->survivalMs,
            );
            $coinBalance = (int) $player['coins'] + $progression->coinsEarned;
            $totalPlayMs = (int) $player['total_play_ms'] + $score->survivalMs;
            $improved = $this->leaderboard->updateBestInTransaction($playerId, $score);

            $updatePlayer = $this->database->prepare(
                'UPDATE players SET coins = :coins, coin_time_remainder_ms = :coin_time_remainder_ms, '
                . 'total_play_ms = :total_play_ms, updated_at = UTC_TIMESTAMP(3) WHERE id = :player_id'
            );
            $updatePlayer->execute([
                'coins' => $coinBalance,
                'coin_time_remainder_ms' => $progression->remainderMs,
                'total_play_ms' => $totalPlayMs,
                'player_id' => $playerId,
            ]);

            $insertRun = $this->database->prepare(
                'INSERT INTO completed_runs '
                . '(run_id, player_id, payload_hash, mode, score, duration_ms, reaction_base_points, '
                . 'multiplier_bonus_points, max_multiplier, multiplier_1_hits, multiplier_2_hits, '
                . 'multiplier_3_hits, multiplier_4_hits, multiplier_5_hits, multiplier_1_base_points, '
                . 'multiplier_2_base_points, multiplier_3_base_points, multiplier_4_base_points, '
                . 'multiplier_5_base_points, coins_awarded, leaderboard_improved) VALUES '
                . '(:run_id, :player_id, :payload_hash, :mode, :score, :duration_ms, :reaction_base_points, '
                . ':multiplier_bonus_points, :max_multiplier, :multiplier_1_hits, :multiplier_2_hits, '
                . ':multiplier_3_hits, :multiplier_4_hits, :multiplier_5_hits, :multiplier_1_base_points, '
                . ':multiplier_2_base_points, :multiplier_3_base_points, :multiplier_4_base_points, '
                . ':multiplier_5_base_points, :coins_awarded, :leaderboard_improved)'
            );
            $insertRun->bindValue(':run_id', $score->runId);
            $insertRun->bindValue(':player_id', $playerId);
            $insertRun->bindValue(':payload_hash', $score->payloadHash(), PDO::PARAM_LOB);
            $insertRun->bindValue(':mode', $score->mode);
            $insertRun->bindValue(':score', $score->score, PDO::PARAM_INT);
            $insertRun->bindValue(':duration_ms', $score->survivalMs, PDO::PARAM_INT);
            $insertRun->bindValue(':reaction_base_points', $score->reactionBasePoints, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_bonus_points', $score->multiplierBonusPoints, PDO::PARAM_INT);
            $insertRun->bindValue(':max_multiplier', $score->maxMultiplier, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_1_hits', $score->multiplierOneHits, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_2_hits', $score->multiplierTwoHits, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_3_hits', $score->multiplierThreeHits, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_4_hits', $score->multiplierFourHits, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_5_hits', $score->multiplierFiveHits, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_1_base_points', $score->multiplierOneBasePoints, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_2_base_points', $score->multiplierTwoBasePoints, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_3_base_points', $score->multiplierThreeBasePoints, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_4_base_points', $score->multiplierFourBasePoints, PDO::PARAM_INT);
            $insertRun->bindValue(':multiplier_5_base_points', $score->multiplierFiveBasePoints, PDO::PARAM_INT);
            $insertRun->bindValue(':coins_awarded', $progression->coinsEarned, PDO::PARAM_INT);
            $insertRun->bindValue(':leaderboard_improved', $improved ? 1 : 0, PDO::PARAM_INT);
            $insertRun->execute();

            $this->database->commit();
            return [
                'improved' => $improved,
                'duplicate' => false,
                'coinsEarned' => $progression->coinsEarned,
                'coinBalance' => $coinBalance,
                'totalPlayMs' => $totalPlayMs,
            ];
        } catch (PDOException $error) {
            $this->rollBack();
            if ($mayRetry && ($error->getCode() === '23000' || $error->getCode() === '40001')) {
                return $this->record($playerId, $score, false);
            }
            throw $error;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    private function lockPlayer(string $playerId): array
    {
        $statement = $this->database->prepare(
            'SELECT coins, coin_time_remainder_ms, total_play_ms FROM players WHERE id = :player_id FOR UPDATE'
        );
        $statement->execute(['player_id' => $playerId]);
        $player = $statement->fetch();
        if (!is_array($player)) {
            throw new ApiException(401, 'Sign in with Google to continue.');
        }
        return $player;
    }

    private function findCompletedRun(string $runId): array|false
    {
        $statement = $this->database->prepare(
            'SELECT player_id, payload_hash, coins_awarded, leaderboard_improved '
            . 'FROM completed_runs WHERE run_id = :run_id FOR UPDATE'
        );
        $statement->execute(['run_id' => $runId]);
        return $statement->fetch();
    }

    private function assertMatchingRun(array $existing, string $playerId, ScoreSubmission $score): void
    {
        $storedHash = $existing['payload_hash'] ?? null;
        if (
            !is_string($storedHash)
            || !hash_equals((string) $existing['player_id'], $playerId)
            || !hash_equals($storedHash, $score->payloadHash())
        ) {
            throw new ApiException(409, 'Run ID has already been used for a different result.');
        }
    }

    private function rollBack(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }
}
