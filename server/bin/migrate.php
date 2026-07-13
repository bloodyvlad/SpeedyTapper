<?php

declare(strict_types=1);

use SpeedyTapper\Config;
use SpeedyTapper\Database;
use SpeedyTapper\LeaderboardRepository;
use SpeedyTapper\MigrationRunner;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/server/autoload.php';

try {
    $config = Config::load($projectRoot);
    $database = Database::connect($config);
    (new MigrationRunner($database, $projectRoot . '/server/migrations'))->run(
        static fn (string $name): int => fwrite(STDOUT, 'Applied ' . $name . PHP_EOL),
    );

    (new LeaderboardRepository($database, $config->seasonId, $config->seasonName))->ensureSeason();
    fwrite(STDOUT, 'Database is ready for ' . $config->seasonId . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
