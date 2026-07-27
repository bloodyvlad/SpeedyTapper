<?php

declare(strict_types=1);

use SpeedyTapper\AppStoreConnectGameCenterClient;
use SpeedyTapper\Config;
use SpeedyTapper\Database;
use SpeedyTapper\GameCenterOutboxWorker;
use SpeedyTapper\GameCenterPublicationRepository;

$root = dirname(__DIR__, 2);
require $root . '/server/autoload.php';

$limit = 50;
$limitWasSet = false;
$backfill = false;
$listHeld = false;
$requeueHeldId = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--backfill') {
        $backfill = true;
        continue;
    }
    if ($argument === '--list-held') {
        $listHeld = true;
        continue;
    }
    if (preg_match('/^--requeue-held=([0-9a-fA-F-]{36})$/D', $argument, $matches) === 1) {
        $requeueHeldId = strtolower($matches[1]);
        continue;
    }
    if (preg_match('/^--limit=([1-9][0-9]{0,2})$/D', $argument, $matches) === 1) {
        $limit = min(500, (int) $matches[1]);
        $limitWasSet = true;
        continue;
    }
    fwrite(
        STDERR,
        "Usage: php server/bin/publish-game-center.php "
            . "[--limit=50] [--backfill] [--list-held] "
            . "[--requeue-held=OUTBOX_UUID]\n",
    );
    exit(2);
}
if (
    ($listHeld && ($backfill || $requeueHeldId !== null))
    || ($requeueHeldId !== null && ($backfill || $limitWasSet))
) {
    fwrite(STDERR, "Held-job inspection and exact recovery must run separately.\n");
    exit(2);
}

try {
    $config = Config::load($root);
    if (!$config->gameCenterPublicationIsConfigured()) {
        throw new RuntimeException('Game Center server publication is not configured.');
    }
    $database = Database::connect($config);
    $lockName = 'speedytapper-game-center-publish-'
        . ($config->gameCenterPreReleased ? 'prerelease' : 'production');
    $lock = $database->prepare('SELECT GET_LOCK(:lock_name, 0)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        fwrite(STDOUT, "Another Game Center publication worker is active.\n");
        exit(0);
    }

    $exitCode = 0;
    try {
        $outbox = new GameCenterPublicationRepository(
            $database,
            $config->gameCenterPlayerIdEncryptionKey ?? '',
            (bool) $config->gameCenterPreReleased,
        );
        $lane = $config->gameCenterPreReleased ? 'prerelease' : 'production';
        if ($listHeld) {
            $payload = [
                'lane' => $lane,
                'heldJobs' => $outbox->heldDiagnostics($limit),
            ];
        } elseif ($requeueHeldId !== null) {
            $requeued = $outbox->requeueHeldById($requeueHeldId);
            $payload = [
                'lane' => $lane,
                'requeuedHeldJobId' => $requeueHeldId,
                'requeuedHeldJobs' => $requeued ? 1 : 0,
            ];
            $exitCode = $requeued ? 0 : 3;
        } else {
            $worker = new GameCenterOutboxWorker(
                $outbox,
                new AppStoreConnectGameCenterClient($config),
            );
            $backfilled = $backfill ? $outbox->backfillAllActiveBindings() : 0;
            $result = $worker->run($limit);
            $payload = [
                'lane' => $lane,
                'backfilledBindings' => $backfilled,
                ...$result,
            ];
            $exitCode = $result['failed'] === 0 ? 0 : 1;
        }
        fwrite(
            STDOUT,
            json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );
    } finally {
        $release = $database->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
    exit($exitCode);
} catch (Throwable $error) {
    fwrite(STDERR, 'Game Center publication failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
