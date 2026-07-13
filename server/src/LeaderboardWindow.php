<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class LeaderboardWindow
{
    public const TOP_COUNT = 5;
    public const CONTEXT_RADIUS = 2;

    public static function select(array $rankedRows, ?string $playerId): array
    {
        $selected = [];
        $playerRank = null;
        foreach ($rankedRows as $row) {
            $rank = (int) $row['rank_position'];
            if ($rank <= self::TOP_COUNT) {
                $selected[$rank] = $row;
            }
            if ($playerId !== null && hash_equals((string) $row['player_id'], $playerId)) {
                $playerRank = $rank;
            }
        }

        if ($playerRank !== null) {
            foreach ($rankedRows as $row) {
                $rank = (int) $row['rank_position'];
                if (abs($rank - $playerRank) <= self::CONTEXT_RADIUS) {
                    $selected[$rank] = $row;
                }
            }
        }

        ksort($selected, SORT_NUMERIC);
        return [
            'rows' => array_values($selected),
            'playerRank' => $playerRank,
        ];
    }

    public static function topPercent(?int $rank, int $total): ?int
    {
        if ($rank === null || $rank < 1 || $total < 1) {
            return null;
        }
        return max(1, min(100, (int) ceil(($rank / $total) * 100)));
    }
}
