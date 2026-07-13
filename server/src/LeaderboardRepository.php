<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class LeaderboardRepository
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $seasonId,
        private readonly string $seasonName,
    ) {
    }

    public function ensureSeason(): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO seasons (id, name) VALUES (:id, :name) '
            . 'ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        $statement->execute(['id' => $this->seasonId, 'name' => $this->seasonName]);
    }

    public function payload(string $mode, ?string $playerId): array
    {
        self::validateMode($mode);
        $rankedRows = $this->rankedRows($mode);
        $window = LeaderboardWindow::select($rankedRows, $playerId);
        $entries = array_map(
            fn (array $row): array => $this->publicEntry($row, $playerId),
            $window['rows'],
        );
        $total = count($rankedRows);

        return [
            'season' => ['id' => $this->seasonId, 'name' => $this->seasonName],
            'mode' => $mode,
            'entries' => $entries,
            'totalEntries' => $total,
            'playerRank' => $window['playerRank'],
            'topPercent' => LeaderboardWindow::topPercent($window['playerRank'], $total),
        ];
    }

    public function rankings(string $playerId): array
    {
        $rankings = [];
        foreach (['normal', 'zen'] as $mode) {
            $payload = $this->payload($mode, $playerId);
            $rankings[$mode] = [
                'rank' => $payload['playerRank'],
                'totalEntries' => $payload['totalEntries'],
                'topPercent' => $payload['topPercent'],
            ];
        }
        return $rankings;
    }

    public function submit(string $playerId, ScoreSubmission $score): array
    {
        $improved = $this->writeBest($playerId, $score, true);
        $payload = $this->payload($score->mode, $playerId);
        $payload['rank'] = $payload['playerRank'];
        $payload['improved'] = $improved;
        return $payload;
    }

    private function writeBest(string $playerId, ScoreSubmission $score, bool $mayRetry): bool
    {
        $this->database->beginTransaction();
        try {
            $select = $this->database->prepare(
                'SELECT id, score, duration_ms, correct_taps FROM leaderboard_entries '
                . 'WHERE season_id = :season_id AND player_id = :player_id AND mode = :mode FOR UPDATE'
            );
            $select->execute([
                'season_id' => $this->seasonId,
                'player_id' => $playerId,
                'mode' => $score->mode,
            ]);
            $current = $select->fetch();

            if (is_array($current) && !$score->isBetterThan($current)) {
                $this->database->commit();
                return false;
            }

            $parameters = $this->scoreParameters($playerId, $score);
            if (is_array($current)) {
                $parameters['id'] = $current['id'];
                $statement = $this->database->prepare(
                    'UPDATE leaderboard_entries SET score = :score, duration_ms = :duration_ms, '
                    . 'fastest_reaction_ms = :fastest_reaction_ms, average_reaction_ms = :average_reaction_ms, '
                    . 'correct_taps = :correct_taps, dodge_count = :dodge_count, '
                    . 'godlike_count = :godlike_count, perfect_count = :perfect_count, '
                    . 'great_count = :great_count, good_count = :good_count, '
                    . 'achieved_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3) WHERE id = :id'
                );
                unset($parameters['season_id'], $parameters['player_id'], $parameters['mode']);
            } else {
                $parameters['id'] = Uuid::v4();
                $statement = $this->database->prepare(
                    'INSERT INTO leaderboard_entries '
                    . '(id, season_id, player_id, mode, score, duration_ms, fastest_reaction_ms, '
                    . 'average_reaction_ms, correct_taps, dodge_count, godlike_count, perfect_count, '
                    . 'great_count, good_count, achieved_at) VALUES '
                    . '(:id, :season_id, :player_id, :mode, :score, :duration_ms, :fastest_reaction_ms, '
                    . ':average_reaction_ms, :correct_taps, :dodge_count, :godlike_count, :perfect_count, '
                    . ':great_count, :good_count, UTC_TIMESTAMP(3))'
                );
            }
            $statement->execute($parameters);
            $this->database->commit();
            return true;
        } catch (PDOException $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            if ($mayRetry && $error->getCode() === '23000') {
                return $this->writeBest($playerId, $score, false);
            }
            throw $error;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function scoreParameters(string $playerId, ScoreSubmission $score): array
    {
        return [
            'season_id' => $this->seasonId,
            'player_id' => $playerId,
            'mode' => $score->mode,
            'score' => $score->score,
            'duration_ms' => $score->survivalMs,
            'fastest_reaction_ms' => $score->fastestReactionMs,
            'average_reaction_ms' => $score->averageReactionMs,
            'correct_taps' => $score->hits,
            'dodge_count' => $score->dodges,
            'godlike_count' => $score->godlikeCount,
            'perfect_count' => $score->perfectCount,
            'great_count' => $score->greatCount,
            'good_count' => $score->goodCount,
        ];
    }

    private function rankedRows(string $mode): array
    {
        $statement = $this->database->prepare(
            'SELECT e.id, e.player_id, p.nickname, e.mode, e.score, e.duration_ms, '
            . 'e.fastest_reaction_ms, e.average_reaction_ms, e.correct_taps, e.dodge_count, '
            . 'e.godlike_count, e.perfect_count, e.great_count, e.good_count, e.achieved_at, '
            . 'ROW_NUMBER() OVER (ORDER BY e.score DESC, '
            . "CASE WHEN e.mode = 'normal' THEN e.duration_ms ELSE 0 END DESC, "
            . 'e.correct_taps DESC, e.achieved_at ASC, e.id ASC) AS rank_position '
            . 'FROM leaderboard_entries e INNER JOIN players p ON p.id = e.player_id '
            . 'WHERE e.season_id = :season_id AND e.mode = :mode '
            . 'ORDER BY rank_position ASC'
        );
        $statement->execute(['season_id' => $this->seasonId, 'mode' => $mode]);
        return $statement->fetchAll();
    }

    private function publicEntry(array $row, ?string $playerId): array
    {
        $fastest = $row['fastest_reaction_ms'] === null ? null : (int) $row['fastest_reaction_ms'];
        $average = $row['average_reaction_ms'] === null ? null : (int) $row['average_reaction_ms'];
        return [
            'id' => (string) $row['id'],
            'rank' => (int) $row['rank_position'],
            'name' => (string) $row['nickname'],
            'mode' => (string) $row['mode'],
            'score' => (int) $row['score'],
            'survivalMs' => (int) $row['duration_ms'],
            'fastestReactionMs' => $fastest,
            'averageReactionMs' => $average,
            'hits' => (int) $row['correct_taps'],
            'dodges' => (int) $row['dodge_count'],
            'speedRatings' => [
                'godlike' => (int) $row['godlike_count'],
                'perfect' => (int) $row['perfect_count'],
                'great' => (int) $row['great_count'],
                'good' => (int) $row['good_count'],
            ],
            'createdAt' => (new \DateTimeImmutable((string) $row['achieved_at'], new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z'),
            'isCurrentPlayer' => $playerId !== null && hash_equals((string) $row['player_id'], $playerId),
        ];
    }

    private static function validateMode(string $mode): void
    {
        if ($mode !== 'normal' && $mode !== 'zen') {
            throw new ApiException(400, 'Mode must be normal or zen.');
        }
    }
}
