<?php

declare(strict_types=1);

use SpeedyTapper\Config;
use SpeedyTapper\Database;
use SpeedyTapper\LeaderboardRepository;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/server/autoload.php';

try {
    $config = Config::load($projectRoot);
    $database = Database::connect($config);
    $database->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations ('
        . 'migration VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
        . 'applied_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $applied = $database->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $known = array_fill_keys($applied, true);
    $migrationPaths = glob($projectRoot . '/server/migrations/*.sql') ?: [];
    sort($migrationPaths, SORT_STRING);

    foreach ($migrationPaths as $path) {
        $name = basename($path);
        if (isset($known[$name])) {
            continue;
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Could not read migration ' . $name);
        }

        try {
            foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
                $database->exec($statement);
            }
            $record = $database->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
            $record->execute(['migration' => $name]);
            fwrite(STDOUT, 'Applied ' . $name . PHP_EOL);
        } catch (Throwable $error) {
            // MySQL/MariaDB implicitly commits DDL. Migrations are idempotent and
            // recorded only after every statement succeeds, so a retry is safe.
            throw $error;
        }
    }

    (new LeaderboardRepository($database, $config->seasonId, $config->seasonName))->ensureSeason();
    fwrite(STDOUT, 'Database is ready for ' . $config->seasonId . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
