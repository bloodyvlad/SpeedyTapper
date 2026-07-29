<?php

declare(strict_types=1);

namespace SpeedyTapper;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Read model for immutable, protocol-verified multiplayer results.
 *
 * The repository deliberately returns no player, participant, match, or result
 * identifiers. Public rows contain only the confirmed nickname, renderable pet,
 * rank, and server-derived gameplay statistics.
 */
final class MultiplayerLeaderboardRepository
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $seasonId,
        private readonly string $seasonName,
    ) {
    }

    public function payload(?string $playerId): array
    {
        $playerId = $playerId === null ? null : self::normalizedPlayerId($playerId);

        return $this->consistentRead(function () use ($playerId): array {
            $total = $this->countEntries();
            $placement = $playerId === null
                ? null
                : $this->bestPlacementForPlayer($playerId);
            $playerRank = $placement['rank'] ?? null;
            $entries = array_map(
                fn (array $row): array => $this->publicEntry($row, $playerId),
                $this->rankedRows($playerRank),
            );

            return [
                'season' => [
                    'id' => $this->seasonId,
                    'name' => $this->seasonName,
                ],
                'mode' => 'multiplayer',
                'entries' => $entries,
                'totalEntries' => $total,
                'playerRank' => $playerRank,
                'topPercent' => LeaderboardWindow::topPercent($playerRank, $total),
            ];
        });
    }

    public function topPayload(): array
    {
        return $this->payload(null);
    }

    public function topScoreForPlayer(string $playerId): ?int
    {
        $playerId = self::normalizedPlayerId($playerId);
        $statement = $this->database->prepare(
            'SELECT MAX(score) FROM multiplayer_results '
            . 'WHERE season_id = :season_id AND player_id = :player_id '
            . "AND verification_status = 'verified'"
        );
        $statement->execute([
            'season_id' => $this->seasonId,
            'player_id' => $playerId,
        ]);
        $score = $statement->fetchColumn();
        return $score === false || $score === null ? null : (int) $score;
    }

    private function countEntries(): int
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM multiplayer_results '
            . 'WHERE season_id = :season_id '
            . "AND verification_status = 'verified'"
        );
        $statement->execute(['season_id' => $this->seasonId]);
        return (int) $statement->fetchColumn();
    }

    /** @return null|array{rank: int} */
    private function bestPlacementForPlayer(string $playerId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT rank_position FROM ('
            . 'SELECT player_id, ROW_NUMBER() OVER (ORDER BY '
            . self::rankingOrderSql() . ') AS rank_position '
            . 'FROM multiplayer_results '
            . 'WHERE season_id = :season_id '
            . "AND verification_status = 'verified'"
            . ') ranked WHERE player_id = :player_id '
            . 'ORDER BY rank_position ASC LIMIT 1'
        );
        $statement->execute([
            'season_id' => $this->seasonId,
            'player_id' => $playerId,
        ]);
        $rank = $statement->fetchColumn();
        return $rank === false ? null : ['rank' => (int) $rank];
    }

    /** @return list<array<string, mixed>> */
    private function rankedRows(?int $contextRank): array
    {
        $contextClause = $contextRank === null
            ? ''
            : ' OR rank_position BETWEEN '
                . max(1, $contextRank - LeaderboardWindow::CONTEXT_RADIUS)
                . ' AND '
                . ($contextRank + LeaderboardWindow::CONTEXT_RADIUS);
        $statement = $this->database->prepare(
            'WITH ranked AS ('
            . 'SELECT r.player_id, p.nickname, p.nickname_confirmed, ps.pet_id, '
            . 'r.placement, r.player_count, r.score, r.duration_ms, '
            . 'r.fastest_reaction_ms, r.average_reaction_ms, r.correct_taps, '
            . 'r.miss_count, r.dodge_count, r.godlike_count, r.perfect_count, '
            . 'r.great_count, r.good_count, r.max_multiplier, r.achieved_at, '
            . 'ROW_NUMBER() OVER (ORDER BY ' . self::rankingOrderSql('r.') . ') '
            . 'AS rank_position '
            . 'FROM multiplayer_results r '
            . 'INNER JOIN players p ON p.id = r.player_id '
            . 'LEFT JOIN player_pet_selection ps '
            . 'ON ps.player_id = r.player_id AND ps.is_visible = 1 '
            . 'WHERE r.season_id = :season_id '
            . "AND r.verification_status = 'verified'"
            . ') SELECT * FROM ranked WHERE rank_position <= '
            . LeaderboardWindow::TOP_COUNT
            . $contextClause
            . ' ORDER BY rank_position ASC'
        );
        $statement->execute(['season_id' => $this->seasonId]);
        return $statement->fetchAll();
    }

    private static function rankingOrderSql(string $prefix = ''): string
    {
        return $prefix . 'score DESC, '
            . $prefix . 'placement ASC, '
            . $prefix . 'duration_ms DESC, '
            . $prefix . 'correct_taps DESC, '
            . $prefix . 'achieved_at ASC, '
            . $prefix . 'id ASC';
    }

    /** @return array<string, mixed> */
    private function publicEntry(array $row, ?string $playerId): array
    {
        $specialPetId = PetCatalog::specialForNickname(
            $row['nickname'] ?? null,
            (bool) ($row['nickname_confirmed'] ?? false),
        );
        $fastest = $row['fastest_reaction_ms'] === null
            ? null
            : (int) $row['fastest_reaction_ms'];
        $average = $row['average_reaction_ms'] === null
            ? null
            : (int) $row['average_reaction_ms'];

        return [
            'rank' => (int) $row['rank_position'],
            'name' => (string) $row['nickname'],
            'petId' => $specialPetId
                ?? (PetCatalog::isRenderable($row['pet_id'] ?? null)
                    ? (string) $row['pet_id']
                    : null),
            'score' => (int) $row['score'],
            'place' => (int) $row['placement'],
            'playerCount' => (int) $row['player_count'],
            'survivalMs' => (int) $row['duration_ms'],
            'fastestReactionMs' => $fastest,
            'averageReactionMs' => $average,
            'hits' => (int) $row['correct_taps'],
            'misses' => (int) $row['miss_count'],
            'dodges' => (int) $row['dodge_count'],
            'maxMultiplier' => (int) $row['max_multiplier'],
            'speedRatings' => [
                'godlike' => (int) $row['godlike_count'],
                'perfect' => (int) $row['perfect_count'],
                'great' => (int) $row['great_count'],
                'good' => (int) $row['good_count'],
            ],
            'createdAt' => self::isoDate((string) $row['achieved_at']),
            'isCurrentPlayer' => $playerId !== null
                && hash_equals((string) $row['player_id'], $playerId),
            'verification' => 'peer_consistent_v1',
        ];
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

    private static function normalizedPlayerId(string $playerId): string
    {
        $playerId = strtolower(trim($playerId));
        if (!Uuid::isValidV4($playerId)) {
            throw new InvalidArgumentException('Multiplayer leaderboard player ID is invalid.');
        }
        return $playerId;
    }

    private static function isoDate(string $value): string
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
