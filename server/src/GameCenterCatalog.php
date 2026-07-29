<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class GameCenterCatalog
{
    public const LEADERBOARD_ARCADE_VERIFIED =
        'com.otcsoftware.pimpopom.arcade.verified';
    public const LEADERBOARD_MULTIPLAYER_VERIFIED =
        'com.otcsoftware.pimpopom.multiplayer.verified';

    /** @var list<string> */
    private const LEADERBOARDS = [
        self::LEADERBOARD_ARCADE_VERIFIED,
        self::LEADERBOARD_MULTIPLAYER_VERIFIED,
    ];

    /** @var array<string, string> */
    private const ACHIEVEMENTS = [
        AchievementCatalog::COMPLETE_ARCADE =>
            'com.otcsoftware.pimpopom.achievement.complete_arcade',
        AchievementCatalog::GODLIKE_SPEED =>
            'com.otcsoftware.pimpopom.achievement.godlike_speed',
        AchievementCatalog::COLLECT_FIVE_COINS =>
            'com.otcsoftware.pimpopom.achievement.collect_5_coins',
        AchievementCatalog::SCORE_OVER_100K =>
            'com.otcsoftware.pimpopom.achievement.score_over_100k',
        AchievementCatalog::BUY_A_PET =>
            'com.otcsoftware.pimpopom.achievement.buy_a_pet',
    ];

    public static function achievementVendorIdentifier(string $achievementId): string
    {
        $vendorIdentifier = self::ACHIEVEMENTS[$achievementId] ?? null;
        if ($vendorIdentifier === null) {
            throw new \InvalidArgumentException('Unknown Game Center achievement.');
        }
        return $vendorIdentifier;
    }

    public static function supportsAchievement(string $achievementId): bool
    {
        return array_key_exists($achievementId, self::ACHIEVEMENTS);
    }

    public static function supportsLeaderboardVendorIdentifier(
        string $vendorIdentifier,
    ): bool {
        return in_array($vendorIdentifier, self::LEADERBOARDS, true);
    }

    /** @return list<string> */
    public static function leaderboards(): array
    {
        return self::LEADERBOARDS;
    }

    public static function achievementIdForVendorIdentifier(string $vendorIdentifier): string
    {
        $achievementId = array_search($vendorIdentifier, self::ACHIEVEMENTS, true);
        if (!is_string($achievementId)) {
            throw new \InvalidArgumentException('Unknown Game Center achievement identifier.');
        }
        return $achievementId;
    }

    /** @return array<string, string> */
    public static function achievements(): array
    {
        return self::ACHIEVEMENTS;
    }
}
