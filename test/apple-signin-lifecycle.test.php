<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use SpeedyTapper\ApiException;
use SpeedyTapper\AppleCredentialRepository;
use SpeedyTapper\AppleSignInTokenClient;
use SpeedyTapper\PlayerIdentityService;

require dirname(__DIR__) . '/server/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
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

$privateKey = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
if (!$privateKey instanceof OpenSSLAsymmetricKey
    || !openssl_pkey_export($privateKey, $privateKeyPem)
) {
    throw new RuntimeException('Could not create the Apple client-secret test key.');
}
$keyPath = tempnam(sys_get_temp_dir(), 'pimpopom-apple-key-');
if ($keyPath === false || file_put_contents($keyPath, $privateKeyPem) === false) {
    throw new RuntimeException('Could not write the Apple client-secret test key.');
}
@chmod($keyPath, 0600);
register_shutdown_function(static fn () => @unlink($keyPath));

$calls = [];
$now = time();
$transport = static function (string $url, array $fields) use (&$calls): array {
    $calls[] = ['url' => $url, 'fields' => $fields];
    if (str_ends_with($url, '/auth/token')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'id_token' => 'header.payload.signature',
                'refresh_token' => 'retained-refresh-token',
            ], JSON_THROW_ON_ERROR),
        ];
    }
    return ['status' => 200, 'body' => ''];
};
$tokenClient = new AppleSignInTokenClient(
    'com.otcsoftware.pimpopom',
    'ABCDEFGHIJ',
    'KLMNOPQRST',
    $keyPath,
    $transport,
    static fn (): int => $now,
);
$exchange = $tokenClient->exchange('one-time-authorization-code');
$assert(
    $exchange->identityToken === 'header.payload.signature'
        && $exchange->refreshToken === 'retained-refresh-token',
    'Apple authorization-code exchange retains the identity token and revocation material.',
);
$assert(
    ($calls[0]['fields']['grant_type'] ?? null) === 'authorization_code'
        && ($calls[0]['fields']['code'] ?? null) === 'one-time-authorization-code'
        && ($calls[0]['fields']['client_id'] ?? null) === 'com.otcsoftware.pimpopom',
    'The exchange sends the server-controlled OAuth client and one-time code fields.',
);
$clientSecret = $calls[0]['fields']['client_secret'] ?? null;
$publicKeyDetails = openssl_pkey_get_details($privateKey);
if (!is_string($clientSecret)
    || !is_array($publicKeyDetails)
    || !is_string($publicKeyDetails['key'] ?? null)
) {
    throw new RuntimeException('Could not inspect the generated Apple client secret.');
}
$decodedSecret = (array) JWT::decode($clientSecret, new Key($publicKeyDetails['key'], 'ES256'));
$encodedHeader = explode('.', $clientSecret, 2)[0];
$headerPadding = (4 - strlen($encodedHeader) % 4) % 4;
$decodedHeaderBytes = base64_decode(
    strtr($encodedHeader, '-_', '+/') . str_repeat('=', $headerPadding),
    true,
);
if (!is_string($decodedHeaderBytes)) {
    throw new RuntimeException('Could not decode the Apple client-secret header.');
}
$decodedHeader = json_decode($decodedHeaderBytes, true, 8, JSON_THROW_ON_ERROR);
$assert(
    ($decodedHeader['alg'] ?? null) === 'ES256'
        && ($decodedHeader['kid'] ?? null) === 'KLMNOPQRST'
        && ($decodedSecret['iss'] ?? null) === 'ABCDEFGHIJ'
        && ($decodedSecret['sub'] ?? null) === 'com.otcsoftware.pimpopom'
        && ($decodedSecret['aud'] ?? null) === 'https://appleid.apple.com'
        && ($decodedSecret['exp'] ?? null) === $now + 300,
    'Apple client secrets are short-lived ES256 JWTs bound to team, key, and client.',
);
$tokenClient->revoke('retained-refresh-token');
$assert(
    count($calls) === 2
        && str_ends_with($calls[1]['url'], '/auth/revoke')
        && ($calls[1]['fields']['token_type_hint'] ?? null) === 'refresh_token'
        && ($calls[1]['fields']['token'] ?? null) === 'retained-refresh-token',
    'Account deletion revokes the retained refresh token at Apple.',
);
$invalidGrantClient = new AppleSignInTokenClient(
    'com.otcsoftware.pimpopom',
    'ABCDEFGHIJ',
    'KLMNOPQRST',
    $keyPath,
    static fn (): array => ['status' => 400, 'body' => '{"error":"invalid_grant"}'],
    static fn (): int => $now,
);
$throwsStatus(
    401,
    static fn () => $invalidGrantClient->exchange('already-used-code'),
    'An already-used Apple authorization code fails as an authentication error.',
);

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(
    'CREATE TABLE players ('
    . 'id TEXT PRIMARY KEY, google_subject_hash BLOB NULL UNIQUE, nickname TEXT NOT NULL, '
    . 'last_login_at TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, '
    . 'updated_at TEXT DEFAULT CURRENT_TIMESTAMP)'
);
$database->exec(
    'CREATE TABLE player_identities ('
    . 'provider TEXT NOT NULL, subject_hash BLOB NOT NULL, '
    . 'player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'linked_at TEXT NOT NULL, last_authenticated_at TEXT NOT NULL, '
    . 'PRIMARY KEY (provider, subject_hash), UNIQUE (player_id, provider))'
);
$database->exec(
    'CREATE TABLE player_apple_authorizations ('
    . 'player_id TEXT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE, '
    . "provider TEXT NOT NULL DEFAULT 'apple', "
    . 'subject_hash BLOB NOT NULL UNIQUE, refresh_token_ciphertext BLOB NOT NULL, '
    . 'refresh_token_iv BLOB NOT NULL, refresh_token_tag BLOB NOT NULL, '
    . 'created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, '
    . 'FOREIGN KEY (provider, subject_hash) '
    . 'REFERENCES player_identities(provider, subject_hash) ON DELETE CASCADE)'
);
$database->exec(
    'CREATE TABLE player_game_center_bindings ('
    . 'player_id TEXT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE, '
    . 'team_player_id_hash BLOB NOT NULL UNIQUE, linked_at TEXT NOT NULL, '
    . 'last_verified_at TEXT NOT NULL)'
);
$database->exec(
    'CREATE TABLE game_center_assertion_uses ('
    . 'assertion_hash BLOB PRIMARY KEY, consumed_at TEXT DEFAULT CURRENT_TIMESTAMP, '
    . 'expires_at TEXT NOT NULL)'
);
$identities = new PlayerIdentityService($database);
$credentials = new AppleCredentialRepository(
    $database,
    'test-only-credential-key-that-is-at-least-thirty-two-bytes',
);
$appleSubject = '001234.test-apple-subject';
$registration = $identities->loginOrRegister(
    'apple',
    $appleSubject,
    true,
    static function (string $playerId) use ($credentials, $appleSubject): void {
        $credentials->storeOrRetainInCurrentTransaction(
            $playerId,
            $appleSubject,
            'first-refresh-token',
        );
    },
);
$playerId = $registration['playerId'];
$stored = $database->query(
    'SELECT refresh_token_ciphertext, refresh_token_iv, refresh_token_tag '
    . 'FROM player_apple_authorizations'
)->fetch(PDO::FETCH_ASSOC);
$assert(
    is_array($stored)
        && is_string($stored['refresh_token_ciphertext'] ?? null)
        && !str_contains($stored['refresh_token_ciphertext'], 'first-refresh-token')
        && strlen((string) ($stored['refresh_token_iv'] ?? '')) === 12
        && strlen((string) ($stored['refresh_token_tag'] ?? '')) === 16,
    'Apple refresh material is retained only as authenticated ciphertext.',
);
$assert(
    $credentials->refreshTokenForDeletion($playerId) === 'first-refresh-token',
    'The retained refresh token can be recovered only for server-side revocation.',
);
$identities->reauthenticate(
    $playerId,
    'apple',
    $appleSubject,
    static function (string $samePlayerId) use ($credentials, $appleSubject): void {
        $credentials->storeOrRetainInCurrentTransaction(
            $samePlayerId,
            $appleSubject,
            'rotated-refresh-token',
        );
    },
);
$assert(
    $credentials->refreshTokenForDeletion($playerId) === 'rotated-refresh-token',
    'A later verified Apple authorization atomically rotates revocation material.',
);

$beforePlayers = (int) $database->query('SELECT COUNT(*) FROM players')->fetchColumn();
$throwsStatus(
    503,
    static fn () => $identities->loginOrRegister(
        'apple',
        'missing-refresh-subject',
        true,
        static function (string $newPlayerId) use ($credentials): void {
            $credentials->storeOrRetainInCurrentTransaction(
                $newPlayerId,
                'missing-refresh-subject',
                null,
            );
        },
    ),
    'A first Apple authorization without revocation material is rejected.',
);
$assert(
    (int) $database->query('SELECT COUNT(*) FROM players')->fetchColumn() === $beforePlayers,
    'Missing revocation material rolls back the tentative profile and wallet owner.',
);
$database->prepare('DELETE FROM players WHERE id = :id')->execute(['id' => $playerId]);
$assert(
    (int) $database->query('SELECT COUNT(*) FROM player_apple_authorizations')->fetchColumn() === 0,
    'Deleting the internal player cascades the encrypted Apple authorization row.',
);

fwrite(STDOUT, "Apple sign-in lifecycle checks passed ({$assertions} assertions).\n");
