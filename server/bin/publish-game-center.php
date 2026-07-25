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
$backfill = false;
$requeueHeld = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--backfill') {
        $backfill = true;
        continue;
    }
    if ($argument === '--requeue-held') {
        $requeueHeld = true;
        continue;
    }
    if (preg_match('/^--limit=([1-9][0-9]{0,2})$/D', $argument, $matches) === 1) {
        $limit = min(500, (int) $matches[1]);
        continue;
    }
    fwrite(
        STDERR,
        "Usage: php server/bin/publish-game-center.php "
            . "[--limit=50] [--backfill] [--requeue-held]\n",
    );
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

    try {
        $outbox = new GameCenterPublicationRepository(
            $database,
            $config->gameCenterPlayerIdEncryptionKey ?? '',
            (bool) $config->gameCenterPreReleased,
        );
        $worker = new GameCenterOutboxWorker(
            $outbox,
            new AppStoreConnectGameCenterClient($config),
        );
        $requeued = $requeueHeld ? $outbox->requeueHeld() : 0;
        $backfilled = $backfill ? $outbox->backfillAllActiveBindings() : 0;
        $result = $worker->run($limit);
        fwrite(
            STDOUT,
            json_encode(
                [
                    'lane' => $config->gameCenterPreReleased ? 'prerelease' : 'production',
                    'requeuedHeldJobs' => $requeued,
                    'backfilledBindings' => $backfilled,
                    ...$result,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );
        exit($result['failed'] === 0 ? 0 : 1);
    } finally {
        $release = $database->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Game Center publication failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
