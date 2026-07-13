<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class AchievementCatalog
{
    public const COMPLETE_ZEN = 'complete_zen';
    public const COMPLETE_ARCADE = 'complete_arcade';
    public const GODLIKE_SPEED = 'godlike_speed';
    public const COLLECT_FIVE_COINS = 'collect_5_coins';
    public const SCORE_OVER_100K = 'score_over_100k';
    public const BUY_A_PET = 'buy_a_pet';

    private const DEFINITIONS = [
        [
            'id' => self::COMPLETE_ZEN,
            'title' => 'Complete Zen mode',
            'description' => 'Finish a full three-minute Zen run.',
            'rewardCoins' => 1,
        ],
        [
            'id' => self::COMPLETE_ARCADE,
            'title' => 'Complete Arcade mode',
            'description' => 'Play until all three Arcade lives are gone.',
            'rewardCoins' => 1,
        ],
        [
            'id' => self::GODLIKE_SPEED,
            'title' => 'Show Godlike speed',
            'description' => 'Make a correct tap in under 250 ms.',
            'rewardCoins' => 1,
        ],
        [
            'id' => self::COLLECT_FIVE_COINS,
            'title' => 'Collect 5 coins',
            'description' => 'Collect five coins in total.',
            'rewardCoins' => 5,
        ],
        [
            'id' => self::SCORE_OVER_100K,
            'title' => 'Score more than 100K',
            'description' => 'Score over 100,000 points in one run.',
            'rewardCoins' => 5,
        ],
        [
            'id' => self::BUY_A_PET,
            'title' => 'Buy a pet',
            'description' => 'Purchase any pet from the shop.',
            'rewardCoins' => 10,
        ],
    ];

    /** @return list<array{id: string, title: string, description: string, rewardCoins: int}> */
    public static function all(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array{id: string, title: string, description: string, rewardCoins: int} */
    public static function require(mixed $id): array
    {
        if (!is_string($id)) {
            throw new ApiException(400, 'Achievement is invalid.');
        }

        foreach (self::DEFINITIONS as $definition) {
            if (hash_equals($definition['id'], $id)) {
                return $definition;
            }
        }

        throw new ApiException(400, 'Achievement is invalid.');
    }
}
