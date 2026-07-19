<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use RuntimeException;
use Throwable;

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

        // Claim the exact marker inode before doing any database work. A later
        // deployment may create a fresh marker while this request is running;
        // removing only our unique claim ensures that newer marker survives.
        $claimPath = $markerPath . '.claimed-' . bin2hex(random_bytes(12));
        if (!@rename($markerPath, $claimPath)) {
            if (!is_file($markerPath)) {
                return false;
            }
            throw new RuntimeException('Deployment migration marker could not be claimed.');
        }

        try {
            (new MigrationRunner($database, $projectRoot . '/server/migrations'))->run();
            $leaderboard->ensureSeason();

            if (!unlink($claimPath)) {
                throw new RuntimeException('Deployment migration marker claim could not be removed.');
            }
        } catch (Throwable $error) {
            try {
                self::restoreClaim($claimPath, $markerPath);
            } catch (Throwable $restoreError) {
                throw new RuntimeException(
                    'Deployment migration failed and its marker could not be restored: '
                    . $restoreError->getMessage(),
                    0,
                    $error,
                );
            }
            throw $error;
        }

        return true;
    }

    private static function restoreClaim(string $claimPath, string $markerPath): void
    {
        if (!is_file($claimPath)) {
            return;
        }

        // A fresh marker belongs to a newer artifact. Keep it pending and
        // discard only the failed claim from the older request.
        if (is_file($markerPath)) {
            if (!unlink($claimPath)) {
                throw new RuntimeException('Obsolete deployment migration marker claim could not be removed.');
            }
            return;
        }

        if (!rename($claimPath, $markerPath)) {
            throw new RuntimeException('Deployment migration marker claim could not be restored.');
        }
    }
}
