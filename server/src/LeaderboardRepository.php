<?php

declare(strict_types=1);

namespace SpeedyTapper;

use LogicException;
use PDO;
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

    public function payload(string $mode, ?string $playerId, ?string $contextEntryId = null): array
    {
        self::validateMode($mode);
        return $this->consistentRead(function () use ($mode, $playerId, $contextEntryId): array {
            $total = $this->countEntries($mode);
            $playerPlacement = $playerId === null
                ? null
                : $this->bestPlacementForPlayer($mode, $playerId);
            $contextPlacement = $contextEntryId === null || $playerId === null
                ? $playerPlacement
                : $this->placementForEntry($mode, $playerId, $contextEntryId);
            if ($contextPlacement === null) {
                $contextPlacement = $playerPlacement;
            }
            $playerRank = $playerPlacement['rank'] ?? null;
            $contextRank = $contextPlacement['rank'] ?? null;
            $resolvedContextEntryId = $contextPlacement['id'] ?? null;
            $rankedRows = $this->rankedRows($mode, $contextRank);
            $entries = array_map(
                fn (array $row): array => $this->publicEntry(
                    $row,
                    $playerId,
                    $resolvedContextEntryId,
                ),
                $rankedRows,
            );

            return [
                'season' => ['id' => $this->seasonId, 'name' => $this->seasonName],
                'mode' => $mode,
                'entries' => $entries,
                'totalEntries' => $total,
                'playerRank' => $playerRank,
                'topPercent' => LeaderboardWindow::topPercent($playerRank, $total),
                'contextRank' => $contextRank,
                'contextTopPercent' => LeaderboardWindow::topPercent($contextRank, $total),
                'contextEntryId' => $resolvedContextEntryId,
            ];
        });
    }

    public function rankings(string $playerId): array
    {
        return $this->consistentRead(function () use ($playerId): array {
            $rankings = [];
            foreach (['normal', 'zen'] as $mode) {
                $total = $this->countEntries($mode);
                $rank = $this->bestPlacementForPlayer($mode, $playerId)['rank'] ?? null;
                $rankings[$mode] = [
                    'rank' => $rank,
                    'totalEntries' => $total,
                    'topPercent' => LeaderboardWindow::topPercent($rank, $total),
                ];
            }
            return $rankings;
        });
    }

    public function insertResultInTransaction(
        string $playerId,
        ScoreSubmission $score,
        string $verificationStatus = 'verified',
    ): bool
    {
        if (!$this->database->inTransaction()) {
            throw new LogicException('A leaderboard insert requires an active transaction.');
        }
        if (!in_array($verificationStatus, ['verified', 'review', 'quarantined'], true)) {
            throw new LogicException('A new result must be verified, held for review, or quarantined.');
        }

        return $this->insertResultRow($playerId, $score, $verificationStatus);
    }

    private function insertResultRow(
        string $playerId,
        ScoreSubmission $score,
        string $verificationStatus,
    ): bool
    {
        $select = $this->database->prepare(
            'SELECT score, duration_ms, correct_taps FROM leaderboard_entries '
            . 'WHERE season_id = :season_id AND player_id = :player_id AND mode = :mode '
            . "AND verification_status IN ('legacy', 'verified') "
            . 'ORDER BY ' . self::rankingOrderSql() . ' LIMIT 1 FOR UPDATE'
        );
        $select->execute([
            'season_id' => $this->seasonId,
            'player_id' => $playerId,
            'mode' => $score->mode,
        ]);
        $current = $select->fetch();
        $improved = $verificationStatus === 'verified'
            && (!is_array($current) || $score->isBetterThan($current));
        $parameters = $this->scoreParameters($playerId, $score);
        $parameters['id'] = $score->runId;
        $parameters['verification_status'] = $verificationStatus;
        $parameters['risk_score'] = $score->riskScore;
        $parameters['risk_reasons'] = json_encode(
            $score->riskFlags,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $statement = $this->database->prepare(
            'INSERT INTO leaderboard_entries '
            . '(id, season_id, player_id, mode, score, duration_ms, fastest_reaction_ms, '
            . 'average_reaction_ms, correct_taps, dodge_count, godlike_count, perfect_count, '
            . 'great_count, good_count, ruleset_id, proof_version, verified_at, verification_status, '
            . 'risk_score, risk_reasons, achieved_at) VALUES '
            . '(:id, :season_id, :player_id, :mode, :score, :duration_ms, :fastest_reaction_ms, '
            . ':average_reaction_ms, :correct_taps, :dodge_count, :godlike_count, :perfect_count, '
            . ':great_count, :good_count, :ruleset_id, :proof_version, UTC_TIMESTAMP(3), '
            . ':verification_status, :risk_score, :risk_reasons, UTC_TIMESTAMP(3))'
        );
        $statement->execute($parameters);
        return $improved;
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
            'ruleset_id' => RunProofValidator::RULESET_ID,
            'proof_version' => RunProofValidator::PROOF_VERSION,
        ];
    }

    private function countEntries(string $mode): int
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM leaderboard_entries WHERE season_id = :season_id AND mode = :mode '
            . "AND verification_status IN ('legacy', 'verified')"
        );
        $statement->execute(['season_id' => $this->seasonId, 'mode' => $mode]);
        return (int) $statement->fetchColumn();
    }

    private function bestPlacementForPlayer(string $mode, string $playerId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, rank_position FROM ('
            . 'SELECT id, player_id, ROW_NUMBER() OVER (ORDER BY ' . self::rankingOrderSql() . ') AS rank_position '
            . 'FROM leaderboard_entries WHERE season_id = :season_id AND mode = :mode '
            . "AND verification_status IN ('legacy', 'verified')"
            . ') ranked WHERE player_id = :player_id ORDER BY rank_position ASC LIMIT 1'
        );
        $statement->execute([
            'season_id' => $this->seasonId,
            'mode' => $mode,
            'player_id' => $playerId,
        ]);
        $placement = $statement->fetch();
        return is_array($placement)
            ? ['id' => (string) $placement['id'], 'rank' => (int) $placement['rank_position']]
            : null;
    }

    private function placementForEntry(string $mode, string $playerId, string $entryId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, rank_position FROM ('
            . 'SELECT id, player_id, ROW_NUMBER() OVER (ORDER BY ' . self::rankingOrderSql() . ') AS rank_position '
            . 'FROM leaderboard_entries WHERE season_id = :season_id AND mode = :mode '
            . "AND verification_status IN ('legacy', 'verified')"
            . ') ranked WHERE id = :entry_id AND player_id = :player_id LIMIT 1'
        );
        $statement->execute([
            'season_id' => $this->seasonId,
            'mode' => $mode,
            'entry_id' => $entryId,
            'player_id' => $playerId,
        ]);
        $placement = $statement->fetch();
        return is_array($placement)
            ? ['id' => (string) $placement['id'], 'rank' => (int) $placement['rank_position']]
            : null;
    }

    private function rankedRows(string $mode, ?int $contextRank): array
    {
        $contextClause = $contextRank === null
            ? ''
            : ' OR rank_position BETWEEN ' . max(1, $contextRank - LeaderboardWindow::CONTEXT_RADIUS)
                . ' AND ' . ($contextRank + LeaderboardWindow::CONTEXT_RADIUS);
        $statement = $this->database->prepare(
            'WITH ranked AS (SELECT e.id, e.player_id, p.nickname, ps.pet_id, e.mode, e.score, e.duration_ms, '
            . 'e.fastest_reaction_ms, e.average_reaction_ms, e.correct_taps, e.dodge_count, '
            . 'e.godlike_count, e.perfect_count, e.great_count, e.good_count, e.achieved_at, '
            . 'e.verification_status, '
            . 'ROW_NUMBER() OVER (ORDER BY ' . self::rankingOrderSql('e.') . ') AS rank_position '
            . 'FROM leaderboard_entries e INNER JOIN players p ON p.id = e.player_id '
            . 'LEFT JOIN player_pet_selection ps ON ps.player_id = e.player_id '
            . "WHERE e.season_id = :season_id AND e.mode = :mode "
            . "AND e.verification_status IN ('legacy', 'verified')) "
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

    private function publicEntry(array $row, ?string $playerId, ?string $contextEntryId): array
    {
        $fastest = $row['fastest_reaction_ms'] === null ? null : (int) $row['fastest_reaction_ms'];
        $average = $row['average_reaction_ms'] === null ? null : (int) $row['average_reaction_ms'];
        return [
            'id' => (string) $row['id'],
            'rank' => (int) $row['rank_position'],
            'name' => (string) $row['nickname'],
            'petId' => PetCatalog::includes($row['pet_id'] ?? null) ? (string) $row['pet_id'] : null,
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
            'isContextResult' => $contextEntryId !== null && hash_equals((string) $row['id'], $contextEntryId),
            'verification' => (string) $row['verification_status'],
        ];
    }

    private static function validateMode(string $mode): void
    {
        if ($mode !== 'normal' && $mode !== 'zen') {
            throw new ApiException(400, 'Mode must be normal or zen.');
        }
    }
}
