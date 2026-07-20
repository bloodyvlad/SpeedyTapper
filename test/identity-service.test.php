<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\GameCenterIdentity;
use SpeedyTapper\PlayerIdentityService;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throwsStatus = static function (int $status, callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $error) {
        $assert($error->status === $status, $message);
        return;
    }
    $assert(false, $message);
};

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(
    'CREATE TABLE players ('
    . 'id TEXT PRIMARY KEY, '
    . 'google_subject_hash BLOB NULL UNIQUE, '
    . 'nickname TEXT NOT NULL, '
    . 'last_login_at TEXT NOT NULL, '
    . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
    . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
    . ')'
);
$database->exec(
    'CREATE TABLE player_identities ('
    . 'provider TEXT NOT NULL, '
    . 'subject_hash BLOB NOT NULL, '
    . 'player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'linked_at TEXT NOT NULL, '
    . 'last_authenticated_at TEXT NOT NULL, '
    . 'PRIMARY KEY (provider, subject_hash), '
    . 'UNIQUE (player_id, provider)'
    . ')'
);
$database->exec(
    'CREATE TABLE player_game_center_bindings ('
    . 'player_id TEXT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE, '
    . 'team_player_id_hash BLOB NOT NULL UNIQUE, '
    . 'linked_at TEXT NOT NULL, '
    . 'last_verified_at TEXT NOT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE player_apple_authorizations ('
    . 'player_id TEXT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE, '
    . "provider TEXT NOT NULL DEFAULT 'apple', "
    . 'subject_hash BLOB NOT NULL UNIQUE, '
    . 'refresh_token_ciphertext BLOB NOT NULL, '
    . 'refresh_token_iv BLOB NOT NULL, '
    . 'refresh_token_tag BLOB NOT NULL, '
    . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
    . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
    . 'FOREIGN KEY (provider, subject_hash) '
    . 'REFERENCES player_identities(provider, subject_hash) ON DELETE CASCADE'
    . ')'
);
$database->exec(
    'CREATE TABLE game_center_assertion_uses ('
    . 'assertion_hash BLOB PRIMARY KEY, '
    . 'consumed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
    . 'expires_at TEXT NOT NULL'
    . ')'
);

$service = new PlayerIdentityService($database);
$googleSubject = '100000000000000000001';
$googleHash = hash('sha256', "google\0" . $googleSubject, true);
$legacyPlayerId = '11111111-1111-4111-8111-111111111111';
$insertPlayer = $database->prepare(
    'INSERT INTO players (id, google_subject_hash, nickname, last_login_at) '
    . 'VALUES (:id, :hash, :nickname, CURRENT_TIMESTAMP)'
);
$insertPlayer->bindValue(':id', $legacyPlayerId);
$insertPlayer->bindValue(':hash', $googleHash, PDO::PARAM_LOB);
$insertPlayer->bindValue(':nickname', 'Existing');
$insertPlayer->execute();
$insertIdentity = $database->prepare(
    'INSERT INTO player_identities '
    . '(provider, subject_hash, player_id, linked_at, last_authenticated_at) '
    . "VALUES ('google', :hash, :player_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
);
$insertIdentity->bindValue(':hash', $googleHash, PDO::PARAM_LOB);
$insertIdentity->bindValue(':player_id', $legacyPlayerId);
$insertIdentity->execute();

$existing = $service->loginOrRegister('google', $googleSubject, false);
$assert(
    $existing === ['playerId' => $legacyPlayerId, 'created' => false],
    'A backfilled Google identity resolves the original internal UUID.',
);

$appleSubject = '000123.abc.def';
$apple = $service->loginOrRegister('apple', $appleSubject, true);
$assert($apple['created'] === true, 'An explicit Apple registration creates a profile.');
$assert(
    $database->query('SELECT COUNT(*) FROM players')->fetchColumn() === 2,
    'Apple registration creates exactly one additional wallet owner.',
);
$appleAgain = $service->loginOrRegister('apple', $appleSubject, false);
$assert(
    $appleAgain['playerId'] === $apple['playerId'] && $appleAgain['created'] === false,
    'Later Apple login resolves the same internal UUID.',
);
$assert(
    $database->query('SELECT COUNT(*) FROM players')->fetchColumn() === 2,
    'Repeated Apple login never creates a duplicate wallet owner.',
);
$throwsStatus(
    409,
    static fn () => $service->loginOrRegister('apple', 'unknown.apple.subject', false),
    'Unknown Apple login cannot silently create an account.',
);
$playersBeforeAtomicFailure = (int) $database->query('SELECT COUNT(*) FROM players')->fetchColumn();
$identitiesBeforeAtomicFailure = (int) $database->query(
    'SELECT COUNT(*) FROM player_identities'
)->fetchColumn();
$throwsStatus(
    503,
    static fn () => $service->loginOrRegister(
        'apple',
        'atomic.failure.subject',
        true,
        static function (): void {
            throw new ApiException(503, 'Simulated credential retention failure.');
        },
    ),
    'A credential-retention failure aborts Apple registration.',
);
$assert(
    (int) $database->query('SELECT COUNT(*) FROM players')->fetchColumn()
        === $playersBeforeAtomicFailure
        && (int) $database->query('SELECT COUNT(*) FROM player_identities')->fetchColumn()
            === $identitiesBeforeAtomicFailure,
    'Failed Apple credential retention leaves no profile, identity, or duplicate wallet behind.',
);

$linked = $service->linkPrimary($legacyPlayerId, 'apple', 'linked.apple.subject');
$assert($linked['linked'] === true, 'An unclaimed Apple identity links to the signed-in UUID.');
$assert(
    $service->bindings($legacyPlayerId) === [
        'google' => true,
        'apple' => true,
        'gameCenter' => false,
    ],
    'Provider status exposes bindings without exposing provider subjects.',
);
$service->reauthenticate($legacyPlayerId, 'apple', 'linked.apple.subject');
$throwsStatus(
    409,
    static fn () => $service->reauthenticate($legacyPlayerId, 'apple', $appleSubject),
    'Reauthentication cannot switch the current session to another UUID.',
);
$throwsStatus(
    409,
    static fn () => $service->linkPrimary($legacyPlayerId, 'apple', $appleSubject),
    'An Apple identity already owned by another UUID cannot be reassigned or merged.',
);
$throwsStatus(
    409,
    static fn () => $service->linkPrimary($legacyPlayerId, 'apple', 'second.apple.subject'),
    'One profile cannot silently replace its existing Apple binding.',
);

$assertionOne = new GameCenterIdentity(
    'T:team-player-one',
    hash('sha256', 'assertion-one', true),
    (int) floor(microtime(true) * 1000),
);
$gameCenter = $service->linkGameCenter($legacyPlayerId, $assertionOne);
$assert($gameCenter['linked'] === true, 'A verified Game Center team identity links to a profile.');
$assert($service->bindings($legacyPlayerId)['gameCenter'], 'Game Center binding is reported.');
$throwsStatus(
    409,
    static fn () => $service->linkGameCenter($legacyPlayerId, $assertionOne),
    'A Game Center assertion cannot be replayed.',
);
$sameTeamFreshProof = new GameCenterIdentity(
    'T:team-player-one',
    hash('sha256', 'assertion-two', true),
    (int) floor(microtime(true) * 1000),
);
$assert(
    $service->linkGameCenter($legacyPlayerId, $sameTeamFreshProof)['linked'] === false,
    'A fresh proof for the already linked Game Center account is idempotent.',
);
$throwsStatus(
    409,
    static fn () => $service->linkGameCenter($apple['playerId'], new GameCenterIdentity(
        'T:team-player-one',
        hash('sha256', 'assertion-three', true),
        (int) floor(microtime(true) * 1000),
    )),
    'A Game Center identity cannot be attached to a second wallet owner.',
);
$throwsStatus(
    409,
    static fn () => $service->linkGameCenter($legacyPlayerId, new GameCenterIdentity(
        'T:other-team-player',
        hash('sha256', 'assertion-four', true),
        (int) floor(microtime(true) * 1000),
    )),
    'A profile cannot replace its Game Center binding implicitly.',
);

$database->prepare('DELETE FROM players WHERE id = :id')->execute(['id' => $legacyPlayerId]);
$assert(
    (int) $database->query('SELECT COUNT(*) FROM player_identities')->fetchColumn() === 1,
    'Deleting a player cascades every one of that profile\'s primary identity bindings.',
);
$assert(
    (int) $database->query('SELECT COUNT(*) FROM player_game_center_bindings')->fetchColumn() === 0,
    'Deleting a player cascades its Game Center binding.',
);
$assert(
    (int) $database->query('SELECT COUNT(*) FROM game_center_assertion_uses')->fetchColumn() >= 2,
    'Short-lived replay evidence remains after player deletion.',
);

$migration = file_get_contents(
    dirname(__DIR__) . '/server/migrations/018_primary_identities_and_game_center.sql'
);
$assert(
    is_string($migration)
        && str_contains($migration, 'INSERT INTO player_identities')
        && str_contains($migration, "'google'")
        && str_contains($migration, 'MODIFY COLUMN google_subject_hash BINARY(32) NULL')
        && str_contains($migration, 'UNIQUE KEY player_identities_player_provider_unique')
        && str_contains($migration, 'UNIQUE KEY player_game_center_team_player_unique')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS player_apple_authorizations')
        && str_contains($migration, 'refresh_token_ciphertext VARBINARY(4096)')
        && str_contains($migration, 'ON DELETE CASCADE'),
    'The production migration preserves Google UUIDs and enforces one-to-one identity ownership.',
);

fwrite(STDOUT, "Identity service checks passed ({$assertions} assertions).\n");
