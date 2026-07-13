<?php

declare(strict_types=1);

use SpeedyTapper\Config;
use SpeedyTapper\Database;
use SpeedyTapper\LeaderboardModerationService;
use SpeedyTapper\MigrationRunner;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/server/autoload.php';

const SPEEDYTAPPER_ADMIN_USAGE = <<<'TEXT'
SpeedyTapper leaderboard moderation

Read-only commands:
  php server/bin/leaderboard-admin.php list [--season=ID] [--mode=normal|zen]
      [--status=legacy|verified|review|quarantined|deleted] [--limit=50]
  php server/bin/leaderboard-admin.php scan [same filters] [--limit=200]
  php server/bin/leaderboard-admin.php show --entry=EXACT_UUID

Single-result mutation commands (dry-run unless --apply is present):
  php server/bin/leaderboard-admin.php approve --entry=EXACT_UUID
      --actor=NAME --reason="REASON" [--apply]
  php server/bin/leaderboard-admin.php reject --entry=EXACT_UUID
      --actor=NAME --reason="REASON" [--apply]
  php server/bin/leaderboard-admin.php quarantine --entry=EXACT_UUID
      --actor=NAME --reason="REASON" [--apply]
  php server/bin/leaderboard-admin.php restore --entry=EXACT_UUID
      --actor=NAME --reason="REASON" [--apply]
  php server/bin/leaderboard-admin.php delete --entry=EXACT_UUID
      --actor=NAME --reason="REASON" [--apply]
  php server/bin/leaderboard-admin.php reconcile --entry=EXACT_UUID
      --actor=NAME --reason="REASON" [--apply]

"delete" is logical and reversible. The tool never accepts a list, wildcard, nickname,
rank, or score as a mutation target. Review a dry-run and the exact UUID before adding
--apply. Every applied transition is appended to the moderation and coin audit logs.
TEXT;

/** @return array<string, string|bool> */
function speedytapperAdminOptions(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if ($argument === '--apply') {
            $options['apply'] = true;
            continue;
        }
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('Invalid option: ' . $argument);
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ($name === '' || $value === '' || $name === 'apply' || isset($options[$name])) {
            throw new InvalidArgumentException('Invalid or repeated option: ' . $argument);
        }
        $options[$name] = $value;
    }
    return $options;
}

function speedytapperAdminString(array $options, string $name, bool $required = false): ?string
{
    $value = $options[$name] ?? null;
    if ($value === null) {
        if ($required) {
            throw new InvalidArgumentException('--' . $name . ' is required.');
        }
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException('--' . $name . ' requires a value.');
    }
    return $value;
}

function speedytapperAdminLimit(array $options, int $default): int
{
    $value = speedytapperAdminString($options, 'limit');
    if ($value === null) {
        return $default;
    }
    if (preg_match('/^[0-9]{1,3}$/D', $value) !== 1) {
        throw new InvalidArgumentException('--limit must be an integer from 1 to 500.');
    }
    return (int) $value;
}

function speedytapperAdminAssertAllowedOptions(array $options, array $allowed): void
{
    $known = array_fill_keys($allowed, true);
    foreach (array_keys($options) as $name) {
        if (!isset($known[$name])) {
            throw new InvalidArgumentException('Option --' . $name . ' is not valid for this command.');
        }
    }
}

try {
    $command = $argv[1] ?? null;
    $options = speedytapperAdminOptions(array_slice($argv, 2));
    if ($command === null || $command === 'help' || isset($options['help'])) {
        fwrite(STDOUT, SPEEDYTAPPER_ADMIN_USAGE . PHP_EOL);
        exit(0);
    }

    $config = Config::load($projectRoot);
    $database = Database::connect($config);
    (new MigrationRunner($database, $projectRoot . '/server/migrations'))->run();
    $moderation = new LeaderboardModerationService($database);

    $result = match ($command) {
        'list' => (function () use ($moderation, $options): array {
            speedytapperAdminAssertAllowedOptions($options, ['season', 'mode', 'status', 'limit']);
            return [
                'command' => 'list',
                'entries' => $moderation->listEntries(
                    speedytapperAdminString($options, 'season'),
                    speedytapperAdminString($options, 'mode'),
                    speedytapperAdminString($options, 'status'),
                    speedytapperAdminLimit($options, 50),
                ),
            ];
        })(),
        'scan' => (function () use ($moderation, $options): array {
            speedytapperAdminAssertAllowedOptions($options, ['season', 'mode', 'status', 'limit']);
            return ['command' => 'scan'] + $moderation->scan(
                speedytapperAdminString($options, 'season'),
                speedytapperAdminString($options, 'mode'),
                speedytapperAdminString($options, 'status'),
                speedytapperAdminLimit($options, 200),
            );
        })(),
        'show' => (function () use ($moderation, $options): array {
            speedytapperAdminAssertAllowedOptions($options, ['entry']);
            return [
                'command' => 'show',
                'result' => $moderation->show(speedytapperAdminString($options, 'entry', true)),
            ];
        })(),
        'approve', 'reject', 'quarantine', 'restore', 'delete' => (function () use ($moderation, $options, $command): array {
            speedytapperAdminAssertAllowedOptions($options, ['entry', 'actor', 'reason', 'apply']);
            return [
                'command' => $command,
                'result' => $moderation->transition(
                    speedytapperAdminString($options, 'entry', true),
                    $command,
                    speedytapperAdminString($options, 'actor', true),
                    speedytapperAdminString($options, 'reason', true),
                    ($options['apply'] ?? false) === true,
                ),
            ];
        })(),
        'reconcile' => (function () use ($moderation, $options): array {
            speedytapperAdminAssertAllowedOptions($options, ['entry', 'actor', 'reason', 'apply']);
            return [
                'command' => 'reconcile',
                'result' => $moderation->reconcile(
                    speedytapperAdminString($options, 'entry', true),
                    speedytapperAdminString($options, 'actor', true),
                    speedytapperAdminString($options, 'reason', true),
                    ($options['apply'] ?? false) === true,
                ),
            ];
        })(),
        default => throw new InvalidArgumentException('Unknown command: ' . $command),
    };

    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    fwrite(STDOUT, PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Leaderboard admin failed: ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Run with --help for exact-UUID usage.' . PHP_EOL);
    exit(1);
}
