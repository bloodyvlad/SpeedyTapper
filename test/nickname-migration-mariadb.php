<?php

declare(strict_types=1);

use SpeedyTapper\MigrationRunner;

require dirname(__DIR__) . '/server/autoload.php';

$dsn = getenv('SPEEDYTAPPER_TEST_MARIADB_DSN');
$user = getenv('SPEEDYTAPPER_TEST_MARIADB_USER') ?: 'root';
$password = getenv('SPEEDYTAPPER_TEST_MARIADB_PASSWORD') ?: 'root';
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('SPEEDYTAPPER_TEST_MARIADB_DSN is required.');
}

$database = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$migration = file_get_contents(
    dirname(__DIR__) . '/server/migrations/021_unique_player_nicknames.sql'
);
if (!is_string($migration)) {
    throw new RuntimeException('Could not load nickname migration.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$createPlayers = static function (PDO $database): void {
    $database->exec('DROP TABLE IF EXISTS players');
    $database->exec(
        'CREATE TABLE players ('
        . 'id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
        . 'nickname VARCHAR(20) NOT NULL, '
        . 'nickname_confirmed TINYINT(1) NOT NULL DEFAULT 0, '
        . 'created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
        . 'updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) '
        . 'ON UPDATE CURRENT_TIMESTAMP(3)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
$runMigration = static function (PDO $database, string $migration): void {
    foreach (MigrationRunner::splitStatements($migration) as $statement) {
        $database->exec($statement);
    }
};

$createPlayers($database);
$insert = $database->prepare(
    'INSERT INTO players (id, nickname, nickname_confirmed) VALUES (?, ?, ?)'
);
$insert->execute(['11111111-1111-4111-8111-111111111111', 'Player 9551', 0]);
$insert->execute(['22222222-2222-4222-8222-222222222222', 'Player 9551', 0]);
$insert->execute(['33333333-3333-4333-8333-333333333333', 'PublicName', 1]);
$insert->execute(['44444444-4444-4444-8444-444444444444', 'Spaced Name', 1]);

$runMigration($database, $migration);
$rows = $database->query(
    'SELECT id, nickname, nickname_confirmed, nickname_unique_key FROM players ORDER BY id'
)->fetchAll();
$assert(
    count(array_unique(array_column($rows, 'nickname'))) === 4,
    'Legacy unconfirmed placeholders are replaced with distinct stable values.',
);
$assert(
    preg_match('/^Player[0-9a-f]{14}$/', (string) $rows[0]['nickname']) === 1
        && preg_match('/^Player[0-9a-f]{14}$/', (string) $rows[1]['nickname']) === 1,
    'Legacy placeholders contain no whitespace.',
);
$assert(
    (int) $rows[3]['nickname_confirmed'] === 0
        && $rows[3]['nickname_unique_key'] === null,
    'A legacy confirmed spaced name is preserved as an account but removed from the public namespace.',
);
$indexCount = (int) $database->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS "
    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'players' "
    . "AND INDEX_NAME = 'players_confirmed_nickname_unique'"
)->fetchColumn();
$assert($indexCount === 1, 'The confirmed-name database unique key is installed.');

try {
    $database->exec(
        "UPDATE players SET nickname = 'publicname', nickname_confirmed = 1 "
        . "WHERE id = '44444444-4444-4444-8444-444444444444'"
    );
    $assert(false, 'Case-only duplicate confirmation must fail.');
} catch (PDOException $error) {
    $assert($error->getCode() === '23000', 'Case-only duplicates fail through the unique key.');
}
$runMigration($database, $migration);
$assert(true, 'The nickname migration safely reruns after completion.');

$createPlayers($database);
$insert = $database->prepare(
    'INSERT INTO players (id, nickname, nickname_confirmed) VALUES (?, ?, 1)'
);
$insert->execute(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Duplicate', 1]);
$insert->execute(['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'duplicate', 1]);
try {
    $runMigration($database, $migration);
    $assert(false, 'Historical confirmed duplicates must stop the migration.');
} catch (PDOException $error) {
    $assert(
        $error->getCode() === '23000'
            && str_contains($error->getMessage(), 'players_confirmed_nickname_unique'),
        'Historical duplicate failure identifies the authoritative unique key.',
    );
}
$database->exec(
    "UPDATE players SET nickname_confirmed = 0 "
    . "WHERE id = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'"
);
$runMigration($database, $migration);
$assert(
    (int) $database->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'players' "
        . "AND INDEX_NAME = 'players_confirmed_nickname_unique'"
    )->fetchColumn() === 1,
    'The migration completes after an operator explicitly resolves a historical duplicate.',
);

fwrite(STDOUT, "MariaDB nickname migration tests passed ({$assertions} assertions).\n");
