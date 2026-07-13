<?php

declare(strict_types=1);

use SpeedyTapper\Config;
use SpeedyTapper\Database;
use SpeedyTapper\MigrationRunner;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/server/autoload.php';

$apply = in_array('--apply', $argv, true);
$limit = 5_000;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') continue;
    if (preg_match('/^--limit=([1-9][0-9]{0,3}|10000)$/D', $argument, $matches) === 1) {
        $limit = (int) $matches[1];
        continue;
    }
    if ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, <<<'TEXT'
Purge unranked run-attempt metadata in bounded batches.

  php server/bin/purge-run-attempts.php [--limit=5000] [--apply]

Dry-run is the default. Stale issued, abandoned, and expired attempts are kept
for 7 days; rejected proof hashes and redacted metadata are kept for 30 days.
Completed attempts and every ranked/reviewed result are never deleted here.
TEXT);
        fwrite(STDOUT, PHP_EOL);
        exit(0);
    }
    throw new InvalidArgumentException('Unknown option: ' . $argument);
}

$config = Config::load($projectRoot);
$database = Database::connect($config);
(new MigrationRunner($database, $projectRoot . '/server/migrations'))->run();

$where = "((status IN ('issued','abandoned','expired') AND updated_at < UTC_TIMESTAMP(3) - INTERVAL 7 DAY) "
    . "OR (status = 'rejected' AND updated_at < UTC_TIMESTAMP(3) - INTERVAL 30 DAY))";
$counts = $database->query(
    'SELECT status, COUNT(*) AS records FROM run_attempts WHERE ' . $where . ' GROUP BY status'
)->fetchAll();
$eligible = array_sum(array_map(static fn (array $row): int => (int) $row['records'], $counts));
$deleted = 0;

if ($apply && $eligible > 0) {
    $database->beginTransaction();
    try {
        $deleted = $database->exec(
            'DELETE FROM run_attempts WHERE ' . $where
            . ' ORDER BY updated_at ASC, run_id ASC LIMIT ' . $limit
        );
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollBack();
        throw $error;
    }
}

fwrite(STDOUT, json_encode([
    'dryRun' => !$apply,
    'eligible' => $eligible,
    'batchLimit' => $limit,
    'deleted' => $deleted,
    'byStatus' => $counts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
fwrite(STDOUT, PHP_EOL);
