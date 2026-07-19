<?php

declare(strict_types=1);

use SpeedyTapper\AppStoreServerApiClient;
use SpeedyTapper\Config;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$decode = static function (string $value): string {
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    if (!is_string($decoded)) throw new RuntimeException('Invalid JWT fixture encoding.');
    return $decoded;
};
$rawSignatureToDer = static function (string $raw): string {
    if (strlen($raw) !== 64) throw new RuntimeException('Expected an ES256 signature.');
    $integer = static function (string $value): string {
        $value = ltrim($value, "\x00");
        if ($value === '') $value = "\x00";
        if ((ord($value[0]) & 0x80) !== 0) $value = "\x00" . $value;
        return "\x02" . chr(strlen($value)) . $value;
    };
    $body = $integer(substr($raw, 0, 32)) . $integer(substr($raw, 32, 32));
    return "\x30" . chr(strlen($body)) . $body;
};

$products = [
    'com.otcsoftware.pimpopom.coins.50.v1' => [
        'type' => 'consumable', 'coins' => 50, 'capability' => 'ad_free',
    ],
    'com.otcsoftware.pimpopom.coins.100.v1' => [
        'type' => 'consumable', 'coins' => 100, 'capability' => 'ad_free',
    ],
    'com.otcsoftware.pimpopom.coins.500.v1' => [
        'type' => 'consumable', 'coins' => 500, 'capability' => 'ad_free',
    ],
    'com.otcsoftware.pimpopom.coins.1000.v1' => [
        'type' => 'consumable', 'coins' => 1000, 'capability' => 'ad_free',
    ],
    'com.otcsoftware.pimpopom.removeads.lifetime' => [
        'type' => 'non_consumable', 'coins' => 0, 'capability' => 'ad_free',
    ],
];
$fixture = __DIR__ . '/fixtures/apple-jws';
$config = new Config(
    databaseHost: 'localhost',
    databasePort: 3306,
    databaseName: 'test',
    databaseUser: 'test',
    databasePassword: 'test',
    googleClientId: 'test.apps.googleusercontent.com',
    seasonId: 'season-1',
    seasonName: 'Season 1',
    storeKitEnvironment: 'Sandbox',
    storeKitProducts: $products,
    storeKitRetentionHmacKey: str_repeat('r', 32),
    storeKitRootCertificatePaths: [$fixture . '/root.pem'],
    storeKitIssuerId: '11111111-2222-4333-8444-555555555555',
    storeKitKeyId: 'TESTKEY123',
    storeKitPrivateKeyPath: $fixture . '/leaf-key.pem',
);

$client = new AppStoreServerApiClient($config);
$jwtMethod = new ReflectionMethod($client, 'jwt');
$before = time();
$jwt = $jwtMethod->invoke($client);
$after = time();
$parts = explode('.', $jwt);
$assert(count($parts) === 3, 'The App Store API bearer token is a compact three-part JWT.');
$header = json_decode($decode($parts[0]), true, 8, JSON_THROW_ON_ERROR);
$payload = json_decode($decode($parts[1]), true, 8, JSON_THROW_ON_ERROR);
$assert(
    $header === ['alg' => 'ES256', 'kid' => 'TESTKEY123', 'typ' => 'JWT'],
    'The App Store API JWT uses the configured key ID and ES256.',
);
$assert(
    ($payload['iss'] ?? null) === '11111111-2222-4333-8444-555555555555'
    && ($payload['aud'] ?? null) === 'appstoreconnect-v1'
    && ($payload['bid'] ?? null) === 'com.otcsoftware.pimpopom'
    && is_int($payload['iat'] ?? null)
    && $payload['iat'] >= $before
    && $payload['iat'] <= $after
    && ($payload['exp'] ?? null) === $payload['iat'] + 300,
    'The JWT scope, audience, bundle, and five-minute lifetime are server controlled.',
);
$publicKey = openssl_pkey_get_public((string) file_get_contents($fixture . '/leaf.pem'));
$assert(
    $publicKey instanceof OpenSSLAsymmetricKey
    && openssl_verify(
        $parts[0] . '.' . $parts[1],
        $rawSignatureToDer($decode($parts[2])),
        $publicKey,
        OPENSSL_ALGO_SHA256,
    ) === 1,
    'The emitted JWT has a valid P-256 ES256 signature in JOSE format.',
);

fwrite(STDOUT, "App Store API client tests passed ({$assertions} assertions).\n");
