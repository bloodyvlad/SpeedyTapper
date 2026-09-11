<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use Throwable;

/** Fresh revision-3 leaderboard; room-authoritative aggregates, not PHP replay. */
final class MultiplayerV2LeaderboardRepository
{
    public function __construct(private readonly PDO $database, private readonly string $seasonId,
        private readonly string $seasonName)
    {
    }

    public function insertInCurrentTransaction(array $payload): void
    {
        if (!$this->database->inTransaction()) throw new \LogicException('Ranking must share result settlement.');
        if (!$payload['rankingEligible']) return;
        $insert = $this->database->prepare('INSERT INTO multiplayer_v2_leaderboard_entries '
            . '(entry_id,match_id,player_id,season_id,placement,player_count,seat,score,survival_ms,hits, '
            . 'misses,dodges,fastest_reaction_ms,average_reaction_ms,max_multiplier) VALUES '
            . '(:id,:match,:player,:season,:place,:count,:seat,:score,:survival,:hits,:misses,:dodges,:fastest,:average,:maximum)');
        foreach ($payload['players'] as $player) {
            // Equal scores share their competition place. Staying alive longer
            // never beats another player who finished with a higher score.
            $place = 1 + count(array_filter($payload['players'],
                static fn (array $other): bool => $other['score'] > $player['score']));
            $insert->execute([
                'id' => Uuid::v4(), 'match' => $payload['matchID'], 'player' => $player['playerID'],
                'season' => $this->seasonId, 'place' => $place, 'count' => count($payload['players']),
                'seat' => $player['seat'], 'score' => $player['score'], 'survival' => $player['survivalMs'],
                'hits' => $player['hits'], 'misses' => $player['misses'], 'dodges' => $player['dodges'],
                'fastest' => $player['fastestReactionMs'],
                'average' => $player['hits'] === 0 ? null : (int) round($player['reactionTotalMs'] / $player['hits']),
                'maximum' => $player['maxMultiplier'],
            ]);
        }
    }

    public function payload(?string $playerId): array
    {
        if ($playerId !== null && !Uuid::isValidV4($playerId)) {
            throw new \InvalidArgumentException('Invalid multiplayer leaderboard player.');
        }
        $playerId = $playerId === null ? null : strtolower($playerId);
        $owns = !$this->database->inTransaction();
        if ($owns) $this->database->beginTransaction();
        try {
            $totalQuery = $this->database->prepare('SELECT COUNT(*) FROM multiplayer_v2_leaderboard_entries WHERE season_id = :season');
            $totalQuery->execute(['season' => $this->seasonId]);
            $total = (int) $totalQuery->fetchColumn();
            $placement = $playerId === null ? null : $this->bestPlacement($playerId);
            $rank = $placement === null ? null : (int) $placement['rank_position'];
            $position = $placement === null ? null : (int) $placement['row_position'];
            $context = $position === null ? '' : ' OR row_position BETWEEN '
                . max(1, $position - LeaderboardWindow::CONTEXT_RADIUS) . ' AND '
                . ($position + LeaderboardWindow::CONTEXT_RADIUS);
            $rows = $this->database->prepare('WITH ranked AS ('
                . 'SELECT r.*,p.nickname,p.nickname_confirmed,ps.pet_id, '
                . 'ROW_NUMBER() OVER (ORDER BY r.score DESC,r.achieved_at ASC,r.entry_id ASC) AS row_position, '
                . 'RANK() OVER (ORDER BY r.score DESC) AS rank_position '
                . 'FROM multiplayer_v2_leaderboard_entries r JOIN players p ON p.id = r.player_id '
                . 'LEFT JOIN player_pet_selection ps ON ps.player_id = r.player_id AND ps.is_visible = 1 '
                . 'WHERE r.season_id = :season) SELECT * FROM ranked WHERE row_position <= '
                . LeaderboardWindow::TOP_COUNT . $context . ' ORDER BY row_position ASC');
            $rows->execute(['season' => $this->seasonId]);
            $result = ['season' => ['id' => $this->seasonId, 'name' => $this->seasonName],
                'mode' => 'multiplayer', 'entries' => array_map(fn (array $row): array => $this->publicEntry($row, $playerId), $rows->fetchAll()),
                'totalEntries' => $total, 'playerRank' => $rank,
                'topPercent' => LeaderboardWindow::topPercent($rank, $total)];
            if ($owns) $this->database->commit();
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    private function bestPlacement(string $playerId): ?array
    {
        $query = $this->database->prepare('WITH ranked AS (SELECT player_id, '
            . 'ROW_NUMBER() OVER (ORDER BY score DESC,achieved_at ASC,entry_id ASC) AS row_position, '
            . 'RANK() OVER (ORDER BY score DESC) AS rank_position FROM multiplayer_v2_leaderboard_entries '
            . 'WHERE season_id = :season) SELECT rank_position,row_position FROM ranked '
            . 'WHERE player_id = :player ORDER BY row_position ASC LIMIT 1');
        $query->execute(['season' => $this->seasonId, 'player' => $playerId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    private function publicEntry(array $row, ?string $playerId): array
    {
        $specialPet = PetCatalog::specialForNickname($row['nickname'], (bool) $row['nickname_confirmed']);
        return [
            // Position is unique window/order identity; rank intentionally ties.
            'position' => (int) $row['row_position'], 'rank' => (int) $row['rank_position'],
            'name' => $row['nickname'], 'petId' => $specialPet ?? (PetCatalog::isRenderable($row['pet_id'] ?? null) ? $row['pet_id'] : null),
            'score' => (int) $row['score'], 'place' => (int) $row['placement'],
            'playerCount' => (int) $row['player_count'], 'survivalMs' => (int) $row['survival_ms'],
            'fastestReactionMs' => $row['fastest_reaction_ms'] === null ? null : (int) $row['fastest_reaction_ms'],
            'averageReactionMs' => $row['average_reaction_ms'] === null ? null : (int) $row['average_reaction_ms'],
            'hits' => (int) $row['hits'], 'misses' => (int) $row['misses'], 'dodges' => (int) $row['dodges'],
            'maxMultiplier' => (int) $row['max_multiplier'],
            // Speed-rating counts were not observed in the room aggregate; omit
            // them instead of fabricating zeros or changing the scoring contract.
            'createdAt' => (new \DateTimeImmutable($row['achieved_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'isCurrentPlayer' => $playerId !== null && hash_equals($row['player_id'], $playerId),
            'verification' => 'server_reported_v2',
        ];
    }
}
