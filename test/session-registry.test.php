<?php

declare(strict_types=1);

use SpeedyTapper\SessionRegistry;
use SpeedyTapper\SessionStore;
use SpeedyTapper\ApiException;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throwsApi = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (ApiException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(
    'CREATE TABLE players ('
    . 'id TEXT PRIMARY KEY'
    . ')'
);
$database->exec(
    'CREATE TABLE player_sessions ('
    . 'session_auth_hash BLOB PRIMARY KEY, '
    . 'player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'expires_at TEXT NOT NULL, '
    . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
    . ')'
);

$playerOne = '1c970c70-c5f7-4a1b-a2ca-d272ed460596';
$playerTwo = '4fa79da2-280f-413e-a27a-4718fb176ed1';
$insertPlayer = $database->prepare('INSERT INTO players (id) VALUES (:id)');
$insertPlayer->execute(['id' => $playerOne]);
$insertPlayer->execute(['id' => $playerTwo]);

$registry = new SessionRegistry($database);
$sessionDirectory = sys_get_temp_dir() . '/speedytapper-session-' . bin2hex(random_bytes(6));
if (!mkdir($sessionDirectory, 0700) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Could not create the session test directory.');
}
session_save_path($sessionDirectory);
session_id('speedytapperopaquesession' . bin2hex(random_bytes(4)));

$session = new SessionStore(false, $registry);
$session->login($playerOne);
$assert($session->playerId() === $playerOne, 'The opaque registry resolves the authenticated player.');

$serializedSession = serialize($_SESSION);
$assert(
    !str_contains($serializedSession, $playerOne),
    'The PHP session stores no raw player UUID.',
);
$firstAuthId = $_SESSION['speedytapper_session_auth_id'] ?? null;
$assert(
    is_string($firstAuthId) && strlen($firstAuthId) === 43,
    'The PHP session stores a 256-bit opaque authentication ID.',
);
$assert(
    $registry->resolve((string) $firstAuthId) === $playerOne,
    'The database maps the opaque authentication ID to the player.',
);
$session->requireRecentPrimaryAuthentication();
$assert(
    ($_SESSION['speedytapper_primary_authenticated_provider'] ?? null) === 'google',
    'A Google login records provider-neutral recent primary authentication.',
);
$appleChallenge = $session->issueAppleChallenge('link', 'com.otcsoftware.pimpopom');
$consumedApple = $session->consumeAppleChallenge(
    $appleChallenge['challengeId'],
    $appleChallenge['state'],
);
$assert(
    $consumedApple['nonce'] === $appleChallenge['nonce']
        && $consumedApple['intent'] === 'link',
    'Apple nonce/state challenge is bound to and consumed from the session.',
);
$throwsApi(
    static fn () => $session->consumeAppleChallenge(
        $appleChallenge['challengeId'],
        $appleChallenge['state'],
    ),
    'Apple challenge is single use.',
);
$gameCenterChallenge = $session->issueGameCenterChallenge();
$consumedGameCenter = $session->consumeGameCenterChallenge($gameCenterChallenge['challengeId']);
$assert(
    is_int($consumedGameCenter['issuedAtMilliseconds'] ?? null)
        && $consumedGameCenter['issuedAtMilliseconds'] <= (int) floor(microtime(true) * 1_000),
    'Game Center linking returns the issuance time that bounds the signed proof.',
);
$throwsApi(
    static fn () => $session->consumeGameCenterChallenge($gameCenterChallenge['challengeId']),
    'Game Center challenge cannot be replayed.',
);

$session->login($playerTwo, 'apple');
$secondAuthId = $_SESSION['speedytapper_session_auth_id'] ?? null;
$assert(
    is_string($secondAuthId) && $secondAuthId !== $firstAuthId,
    'A new login rotates the opaque authentication ID.',
);
$assert($registry->resolve((string) $firstAuthId) === null, 'Rotation revokes the previous mapping.');
$assert($session->playerId() === $playerTwo, 'The rotated mapping resolves only the new player.');
$assert(
    ($_SESSION['speedytapper_primary_authenticated_provider'] ?? null) === 'apple',
    'An Apple login is first-class primary authentication for the same session registry.',
);

$registry->revoke((string) $secondAuthId);
$assert($session->playerId() === null, 'A missing server-side mapping fails closed.');
$assert(
    !isset($_SESSION['speedytapper_session_auth_id']),
    'A failed lookup removes stale authenticated state from the PHP session.',
);
$_SESSION['speedytapper_player_id'] = $playerTwo;
$assert($session->playerId() === null, 'A legacy raw player UUID cannot authenticate.');
$assert(
    !isset($_SESSION['speedytapper_player_id']),
    'A legacy raw player UUID is removed when the old session is encountered.',
);

$cascadeAuthOne = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$cascadeAuthTwo = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$registry->rotate(null, $cascadeAuthOne, $playerOne);
$registry->rotate(null, $cascadeAuthTwo, $playerOne);
$database->prepare('DELETE FROM players WHERE id = :id')->execute(['id' => $playerOne]);
$assert($registry->resolve($cascadeAuthOne) === null, 'Player deletion invalidates the first browser session.');
$assert($registry->resolve($cascadeAuthTwo) === null, 'Player deletion invalidates every browser session.');
$assert(
    (int) $database->query('SELECT COUNT(*) FROM player_sessions')->fetchColumn() === 0,
    'The account deletion cascade removes all server-side mappings.',
);

$session->login($playerTwo);
$logoutAuthId = $_SESSION['speedytapper_session_auth_id'] ?? null;
$session->logout();
$assert(
    is_string($logoutAuthId) && $registry->resolve($logoutAuthId) === null,
    'Logout revokes its server-side mapping.',
);

$migration = file_get_contents(dirname(__DIR__) . '/server/migrations/015_player_sessions.sql');
$assert(
    is_string($migration)
        && str_contains($migration, 'session_auth_hash BINARY(32)')
        && str_contains($migration, 'ON DELETE CASCADE'),
    'The production migration stores only a digest and cascades account deletion.',
);

@rmdir($sessionDirectory);
fwrite(STDOUT, "Session registry checks passed ({$assertions} assertions).\n");
