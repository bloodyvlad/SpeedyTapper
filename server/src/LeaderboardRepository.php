<?php

declare(strict_types=1);

namespace SpeedyTapper;

use LogicException;
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
        return $this->consistentRead(function () use ($mode, $playerId): array {
            $total = $this->countEntries($mode);
            $playerRank = $playerId === null ? null : $this->rankForPlayer($mode, $playerId);
            $rankedRows = $this->rankedRows($mode, $playerRank);
            $entries = array_map(
                fn (array $row): array => $this->publicEntry($row, $playerId),
                $rankedRows,
            );

            return [
                'season' => ['id' => $this->seasonId, 'name' => $this->seasonName],
                'mode' => $mode,
                'entries' => $entries,
                'totalEntries' => $total,
                'playerRank' => $playerRank,
                'topPercent' => LeaderboardWindow::topPercent($playerRank, $total),
            ];
        });
    }

    public function rankings(string $playerId): array
    {
        return $this->consistentRead(function () use ($playerId): array {
            $rankings = [];
            foreach (['normal', 'zen'] as $mode) {
                $total = $this->countEntries($mode);
                $rank = $this->rankForPlayer($mode, $playerId);
                $rankings[$mode] = [
                    'rank' => $rank,
                    'totalEntries' => $total,
                    'topPercent' => LeaderboardWindow::topPercent($rank, $total),
                ];
            }
            return $rankings;
        });
    }

    public function submit(string $playerId, ScoreSubmission $score): array
    {
        $improved = $this->writeBestTransaction($playerId, $score, true);
        $payload = $this->payload($score->mode, $playerId);
        $payload['rank'] = $payload['playerRank'];
        $payload['improved'] = $improved;
        return $payload;
    }

    public function updateBestInTransaction(string $playerId, ScoreSubmission $score): bool
    {
        if (!$this->database->inTransaction()) {
            throw new LogicException('A leaderboard update requires an active transaction.');
        }

        return $this->writeBestRow($playerId, $score);
    }

    private function writeBestTransaction(string $playerId, ScoreSubmission $score, bool $mayRetry): bool
    {
        $this->database->beginTransaction();
        try {
            $improved = $this->updateBestInTransaction($playerId, $score);
            $this->database->commit();
            return $improved;
        } catch (PDOException $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            if ($mayRetry && $error->getCode() === '23000') {
                return $this->writeBestTransaction($playerId, $score, false);
            }
            throw $error;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function writeBestRow(string $playerId, ScoreSubmission $score): bool
    {
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
        return true;
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

    private function countEntries(string $mode): int
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM leaderboard_entries WHERE season_id = :season_id AND mode = :mode'
        );
        $statement->execute(['season_id' => $this->seasonId, 'mode' => $mode]);
        return (int) $statement->fetchColumn();
    }

    private function rankForPlayer(string $mode, string $playerId): ?int
    {
        $statement = $this->database->prepare(
            'SELECT rank_position FROM ('
            . 'SELECT player_id, ROW_NUMBER() OVER (ORDER BY ' . self::rankingOrderSql() . ') AS rank_position '
            . 'FROM leaderboard_entries WHERE season_id = :season_id AND mode = :mode'
            . ') ranked WHERE player_id = :player_id LIMIT 1'
        );
        $statement->execute([
            'season_id' => $this->seasonId,
            'mode' => $mode,
            'player_id' => $playerId,
        ]);
        $rank = $statement->fetchColumn();
        return $rank === false ? null : (int) $rank;
    }

    private function rankedRows(string $mode, ?int $playerRank): array
    {
        $contextClause = $playerRank === null
            ? ''
            : ' OR rank_position BETWEEN ' . max(1, $playerRank - LeaderboardWindow::CONTEXT_RADIUS)
                . ' AND ' . ($playerRank + LeaderboardWindow::CONTEXT_RADIUS);
        $statement = $this->database->prepare(
            'WITH ranked AS (SELECT e.id, e.player_id, p.nickname, e.mode, e.score, e.duration_ms, '
            . 'e.fastest_reaction_ms, e.average_reaction_ms, e.correct_taps, e.dodge_count, '
            . 'e.godlike_count, e.perfect_count, e.great_count, e.good_count, e.achieved_at, '
            . 'ROW_NUMBER() OVER (ORDER BY ' . self::rankingOrderSql('e.') . ') AS rank_position '
            . 'FROM leaderboard_entries e INNER JOIN players p ON p.id = e.player_id '
            . 'WHERE e.season_id = :season_id AND e.mode = :mode) '
            . 'SELECT * FROM ranked WHERE rank_position <= ' . LeaderboardWindow::TOP_COUNT
            . $contextClause . ' ORDER BY rank_position ASC'
        );
        $statement->execute(['season_id' => $this->seasonId, 'mode' => $mode]);
        return $statement->fetchAll();
    }

    private static function rankingOrderSql(string $prefix = ''): string
    {
        return $prefix . 'score DESC, '
            . "CASE WHEN " . $prefix . "mode = 'normal' THEN " . $prefix . 'duration_ms ELSE 0 END DESC, '
            . $prefix . 'correct_taps DESC, ' . $prefix . 'achieved_at ASC, ' . $prefix . 'id ASC';
    }

    private function consistentRead(callable $callback): mixed
    {
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->database->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
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
