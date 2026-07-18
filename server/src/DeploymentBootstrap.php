<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;

final class DeploymentBootstrap
{
    public const MARKER_PATH = 'server/.migrations-pending';

    public static function migrateIfMarked(
        PDO $database,
        string $projectRoot,
        LeaderboardRepository $leaderboard,
    ): bool {
        $markerPath = rtrim($projectRoot, '/') . '/' . self::MARKER_PATH;
        if (!is_file($markerPath)) {
            return false;
        }

        (new MigrationRunner($database, $projectRoot . '/server/migrations'))->run();
        $leaderboard->ensureSeason();

        if (is_file($markerPath) && !unlink($markerPath)) {
            throw new \RuntimeException('Deployment migration marker could not be removed.');
        }

        return true;
    }
}
