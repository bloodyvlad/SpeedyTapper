<?php

declare(strict_types=1);

use SpeedyTapper\AchievementService;
use SpeedyTapper\ApiException;
use SpeedyTapper\CoinWalletRepository;
use SpeedyTapper\PetShopService;
use SpeedyTapper\PlayerRepository;
use SpeedyTapper\ThemeShopService;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throwsStatus = static function (int $status, callable $operation, string $message) use ($assert): void {
    try {
        $operation();
    } catch (ApiException $error) {
        $assert($error->status === $status, $message);
        return;
    }
    $assert(false, $message);
};

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
@$database->sqliteCreateFunction(
    'UTC_TIMESTAMP',
    static fn (int $precision): string => '2026-07-28 12:00:00.000',
    1,
);
$database->exec(
    'CREATE TABLE players ('
    . 'id TEXT PRIMARY KEY, '
    . 'nickname TEXT COLLATE NOCASE NOT NULL, '
    . 'nickname_confirmed INTEGER NOT NULL DEFAULT 0, '
    . 'coins INTEGER NOT NULL DEFAULT 0, '
    . 'total_play_ms INTEGER NOT NULL DEFAULT 0, '
    . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
    . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
    . ')'
);
$database->exec(
    'CREATE UNIQUE INDEX players_confirmed_nickname_unique '
    . 'ON players (nickname COLLATE NOCASE) WHERE nickname_confirmed = 1'
);
$database->exec('CREATE TABLE player_roles (player_id TEXT NOT NULL, role TEXT NOT NULL)');
$database->exec(
    'CREATE TABLE player_pets (player_id TEXT NOT NULL, pet_id TEXT NOT NULL, acquired_at TEXT NOT NULL)'
);
$database->exec(
    'CREATE TABLE player_pet_selection '
    . '(player_id TEXT NOT NULL, pet_id TEXT NOT NULL, is_visible INTEGER NOT NULL)'
);
$database->exec(
    'CREATE TABLE player_themes (player_id TEXT NOT NULL, theme_id TEXT NOT NULL, acquired_at TEXT NOT NULL)'
);
$database->exec(
    'CREATE TABLE player_theme_selection (player_id TEXT NOT NULL, theme_id TEXT NOT NULL)'
);

$wallets = new CoinWalletRepository($database);
$achievements = new AchievementService($database, $wallets);
$repository = new PlayerRepository(
    $database,
    new PetShopService($database, $achievements, $wallets),
    new ThemeShopService($database, $wallets),
);

$insert = $database->prepare(
    'INSERT INTO players (id, nickname, nickname_confirmed, created_at, updated_at) '
    . 'VALUES (:id, :nickname, :confirmed, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
);
$insert->execute(['id' => 'player-one', 'nickname' => 'Player9551', 'confirmed' => 1]);
$insert->execute(['id' => 'player-two', 'nickname' => 'OtherPlayer', 'confirmed' => 1]);
$insert->execute(['id' => 'player-three', 'nickname' => 'Playerplaceholder', 'confirmed' => 0]);

$assert(
    $repository->nicknameAvailability('player-one', 'Player9551')
        === ['nickname' => 'Player9551', 'available' => true],
    'A player may keep their unchanged current name.',
);
$assert(
    $repository->nicknameAvailability('player-two', 'player9551')
        === ['nickname' => 'player9551', 'available' => false],
    'Availability uses the same case-insensitive namespace as the database.',
);
$assert(
    $repository->nicknameAvailability('player-two', 'FreshName')
        === ['nickname' => 'FreshName', 'available' => true],
    'An unclaimed valid player name is available.',
);
$assert(
    $repository->nicknameAvailability('player-two', 'Playerplaceholder')
        === ['nickname' => 'Playerplaceholder', 'available' => true],
    'An unconfirmed placeholder does not reserve the public-name namespace.',
);

$throwsStatus(
    400,
    static fn () => $repository->nicknameAvailability('player-two', 'Player 9551'),
    'Availability applies the same no-whitespace validation as save.',
);
$throwsStatus(
    409,
    static fn () => $repository->updateNickname('player-two', 'PLAYER9551'),
    'The database-authoritative save race returns a friendly conflict.',
);
$assert(
    $database->query("SELECT nickname FROM players WHERE id = 'player-two'")->fetchColumn()
        === 'OtherPlayer',
    'A rejected duplicate save leaves the existing player name unchanged.',
);

$updated = $repository->updateNickname('player-three', 'FreshName');
$assert(
    $updated['nickname'] === 'FreshName' && $updated['nicknameConfirmed'] === true,
    'Saving an available name confirms the profile.',
);

fwrite(STDOUT, "Nickname/profile tests passed ({$assertions} assertions).\n");
