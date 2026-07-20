<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use SpeedyTapper\ApiException;
use SpeedyTapper\AppleSignInIdentityVerifier;
use SpeedyTapper\GameCenterIdentityVerifier;

require dirname(__DIR__) . '/server/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';

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
$base64Url = static fn (string $value): string => rtrim(
    strtr(base64_encode($value), '+/', '-_'),
    '=',
);

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($key === false || !openssl_pkey_export($key, $privateKeyPem)) {
    throw new RuntimeException('Could not generate the Apple verifier test key.');
}
$keyDetails = openssl_pkey_get_details($key);
if (!is_array($keyDetails) || !is_array($keyDetails['rsa'] ?? null)) {
    throw new RuntimeException('Could not inspect the Apple verifier test key.');
}
$kid = 'test-apple-key';
$jwks = ['keys' => [[
    'kty' => 'RSA',
    'kid' => $kid,
    'use' => 'sig',
    'alg' => 'RS256',
    'n' => $base64Url($keyDetails['rsa']['n']),
    'e' => $base64Url($keyDetails['rsa']['e']),
]]];
$now = 1_800_000_000;
$fetches = 0;
$appleVerifier = new AppleSignInIdentityVerifier(
    ['com.otcsoftware.pimpopom'],
    static function () use ($jwks, &$fetches): array {
        $fetches++;
        return $jwks;
    },
    static fn (): int => $now,
);
$nonce = $base64Url(str_repeat('n', 32));
$claims = [
    'iss' => 'https://appleid.apple.com',
    'aud' => 'com.otcsoftware.pimpopom',
    'sub' => '001234.abcdef',
    'nonce' => $nonce,
    'iat' => $now - 5,
    'exp' => $now + 300,
];
$token = JWT::encode($claims, $privateKeyPem, 'RS256', $kid);
$identity = $appleVerifier->verify($token, $nonce, 'com.otcsoftware.pimpopom');
$assert($identity->subject === '001234.abcdef', 'A valid nonce-bound Apple token is accepted.');
$assert($identity->audience === 'com.otcsoftware.pimpopom', 'Apple audience is retained.');
$assert($fetches === 1, 'Apple JWKS is memoized only within the verifier request lifetime.');
$appleVerifier->verify($token, $nonce, 'com.otcsoftware.pimpopom');
$assert($fetches === 1, 'Repeated verification does not refetch the same request-local JWKS.');
$throwsStatus(
    401,
    static fn () => $appleVerifier->verify($token, $base64Url(str_repeat('x', 32)), 'com.otcsoftware.pimpopom'),
    'Apple nonce mismatch is rejected.',
);
$throwsStatus(
    401,
    static fn () => $appleVerifier->verify($token, $nonce, 'wrong.client'),
    'An unconfigured Apple audience is rejected before token trust.',
);
$wrongIssuer = JWT::encode(
    [...$claims, 'iss' => 'https://attacker.example'],
    $privateKeyPem,
    'RS256',
    $kid,
);
$throwsStatus(
    401,
    static fn () => $appleVerifier->verify($wrongIssuer, $nonce, 'com.otcsoftware.pimpopom'),
    'Wrong Apple issuer is rejected.',
);
$expired = JWT::encode(
    [...$claims, 'iat' => $now - 500, 'exp' => $now - 100],
    $privateKeyPem,
    'RS256',
    $kid,
);
$throwsStatus(
    401,
    static fn () => $appleVerifier->verify($expired, $nonce, 'com.otcsoftware.pimpopom'),
    'Expired Apple token is rejected.',
);
$remoteHeader = JWT::encode(
    $claims,
    $privateKeyPem,
    'RS256',
    $kid,
    ['jku' => 'https://attacker.example/keys'],
);
$throwsStatus(
    401,
    static fn () => $appleVerifier->verify($remoteHeader, $nonce, 'com.otcsoftware.pimpopom'),
    'A token cannot choose a remote signing-key origin.',
);

$jwksCacheDirectory = sys_get_temp_dir() . '/pimpopom-jwks-' . bin2hex(random_bytes(8));
if (!mkdir($jwksCacheDirectory, 0700)) {
    throw new RuntimeException('Could not create the Apple JWKS cache test directory.');
}
$jwksCachePath = $jwksCacheDirectory . '/apple-jwks.json';
$sharedCacheFetches = 0;
$firstCachedVerifier = new AppleSignInIdentityVerifier(
    ['com.otcsoftware.pimpopom'],
    static function () use ($jwks, &$sharedCacheFetches): array {
        $sharedCacheFetches++;
        return $jwks;
    },
    static fn (): int => $now,
    $jwksCachePath,
);
$firstCachedVerifier->verify($token, $nonce, 'com.otcsoftware.pimpopom');
$secondCachedVerifier = new AppleSignInIdentityVerifier(
    ['com.otcsoftware.pimpopom'],
    static function () use (&$sharedCacheFetches): array {
        $sharedCacheFetches++;
        throw new RuntimeException('A fresh private JWKS cache must avoid a second download.');
    },
    static fn (): int => $now,
    $jwksCachePath,
);
$secondCachedVerifier->verify($token, $nonce, 'com.otcsoftware.pimpopom');
$assert(
    $sharedCacheFetches === 1
        && is_file($jwksCachePath)
        && ((fileperms($jwksCachePath) ?: 0) & 0o077) === 0,
    'Apple JWKS is shared only through a private cache file with owner-only permissions.',
);

$rotatedKey = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
]);
if ($rotatedKey === false || !openssl_pkey_export($rotatedKey, $rotatedPrivateKeyPem)) {
    throw new RuntimeException('Could not generate the rotated Apple verifier test key.');
}
$rotatedKeyDetails = openssl_pkey_get_details($rotatedKey);
if (!is_array($rotatedKeyDetails) || !is_array($rotatedKeyDetails['rsa'] ?? null)) {
    throw new RuntimeException('Could not inspect the rotated Apple verifier test key.');
}
$rotatedKid = 'rotated-apple-key';
$rotatedJwks = ['keys' => [[
    'kty' => 'RSA',
    'kid' => $rotatedKid,
    'use' => 'sig',
    'alg' => 'RS256',
    'n' => $base64Url($rotatedKeyDetails['rsa']['n']),
    'e' => $base64Url($rotatedKeyDetails['rsa']['e']),
]]];
$rotatedToken = JWT::encode($claims, $rotatedPrivateKeyPem, 'RS256', $rotatedKid);
$rotationFetches = 0;
$rotationVerifier = new AppleSignInIdentityVerifier(
    ['com.otcsoftware.pimpopom'],
    static function () use ($rotatedJwks, &$rotationFetches): array {
        $rotationFetches++;
        return $rotatedJwks;
    },
    static fn (): int => $now,
    $jwksCachePath,
);
$rotatedIdentity = $rotationVerifier->verify(
    $rotatedToken,
    $nonce,
    'com.otcsoftware.pimpopom',
);
$rotationVerifier->verify($rotatedToken, $nonce, 'com.otcsoftware.pimpopom');
$assert(
    $rotatedIdentity->subject === '001234.abcdef' && $rotationFetches === 1,
    'An unknown Apple key ID bypasses a fresh shared cache once and then remains request-local.',
);
@unlink($jwksCachePath);
@rmdir($jwksCacheDirectory);

$unsafeCacheDirectory = sys_get_temp_dir() . '/pimpopom-jwks-unsafe-' . bin2hex(random_bytes(8));
if (!mkdir($unsafeCacheDirectory, 0777)) {
    throw new RuntimeException('Could not create the unsafe Apple JWKS cache test directory.');
}
chmod($unsafeCacheDirectory, 0777);
$unsafeCachePath = $unsafeCacheDirectory . '/apple-jwks.json';
file_put_contents($unsafeCachePath, json_encode([
    'version' => 1,
    'fetchedAt' => $now,
    'jwks' => ['keys' => []],
], JSON_THROW_ON_ERROR));
chmod($unsafeCachePath, 0600);
$unsafeCacheDownloads = 0;
$unsafeCacheVerifier = new AppleSignInIdentityVerifier(
    ['com.otcsoftware.pimpopom'],
    static function () use ($jwks, &$unsafeCacheDownloads): array {
        $unsafeCacheDownloads++;
        return $jwks;
    },
    static fn (): int => $now,
    $unsafeCachePath,
);
$unsafeCacheVerifier->verify($token, $nonce, 'com.otcsoftware.pimpopom');
$assert(
    $unsafeCacheDownloads === 1,
    'Apple JWKS ignores a pre-seeded cache in a group/world-accessible directory.',
);
@unlink($unsafeCachePath);
@rmdir($unsafeCacheDirectory);

$projectRoot = dirname(__DIR__);
$reviewedRoot = openssl_x509_read(
    file_get_contents($projectRoot . '/server/certs/DigiCertTrustedRootG4.pem') ?: ''
);
$reviewedIntermediate = openssl_x509_read(
    file_get_contents(
        $projectRoot . '/server/certs/DigiCertTrustedG4CodeSigningRSA4096SHA3842021CA1.pem'
    ) ?: ''
);
$assert(
    $reviewedRoot instanceof OpenSSLCertificate
        && strtolower(openssl_x509_fingerprint($reviewedRoot, 'sha256') ?: '')
            === '552f7bdcf1a7af9e6ce672017f4f12abf77240c78e761ac203d1d9d20ac89988'
        && $reviewedIntermediate instanceof OpenSSLCertificate
        && strtolower(openssl_x509_fingerprint($reviewedIntermediate, 'sha256') ?: '')
            === '46011ede1c147eb2bc731a539b7c047b7ee93e48b9d3c3ba710ce132bbdfac6b',
    'Bundled Game Center trust anchors match the reviewed DigiCert fingerprints.',
);

$certificateDirectory = sys_get_temp_dir() . '/pimpopom-game-center-' . bin2hex(random_bytes(8));
if (!mkdir($certificateDirectory, 0700)) {
    throw new RuntimeException('Could not create the Game Center certificate test directory.');
}
register_shutdown_function(static function () use ($certificateDirectory): void {
    foreach (glob($certificateDirectory . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($certificateDirectory);
});
$opensslConfig = $certificateDirectory . '/openssl.cnf';
$opensslConfigContents = <<<'CONFIG'
[ req ]
distinguished_name = req_distinguished_name
prompt = no
default_md = sha256

[ req_distinguished_name ]
CN = Test

[ v3_root ]
basicConstraints = critical,CA:TRUE,pathlen:1
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer

[ v3_intermediate ]
basicConstraints = critical,CA:TRUE,pathlen:0
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer

[ v3_game_center ]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = critical,codeSigning
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
CONFIG;
if (file_put_contents($opensslConfig, $opensslConfigContents) === false) {
    throw new RuntimeException('Could not write the Game Center OpenSSL test configuration.');
}
$newRsaKey = static function (): OpenSSLAsymmetricKey {
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);
    if (!$key instanceof OpenSSLAsymmetricKey) {
        throw new RuntimeException('Could not generate a Game Center certificate test key.');
    }
    return $key;
};
$signCertificate = static function (
    array $subject,
    OpenSSLAsymmetricKey $subjectKey,
    OpenSSLCertificate|false|null $issuerCertificate,
    OpenSSLAsymmetricKey $issuerKey,
    string $extensionSection,
    int $serial,
) use ($opensslConfig): OpenSSLCertificate {
    $csr = openssl_csr_new($subject, $subjectKey, [
        'config' => $opensslConfig,
        'digest_alg' => 'sha256',
    ]);
    if (!$csr instanceof OpenSSLCertificateSigningRequest) {
        throw new RuntimeException('Could not create a Game Center certificate test request.');
    }
    $certificate = openssl_csr_sign(
        $csr,
        $issuerCertificate,
        $issuerKey,
        3_650,
        [
            'config' => $opensslConfig,
            'digest_alg' => 'sha256',
            'x509_extensions' => $extensionSection,
        ],
        $serial,
    );
    if (!$certificate instanceof OpenSSLCertificate) {
        throw new RuntimeException('Could not sign a Game Center test certificate.');
    }
    return $certificate;
};
$rootKey = $newRsaKey();
$rootCertificate = $signCertificate(
    ['C' => 'US', 'O' => 'PimPoPom Test Root', 'CN' => 'PimPoPom Test Root'],
    $rootKey,
    null,
    $rootKey,
    'v3_root',
    1,
);
$intermediateKey = $newRsaKey();
$intermediateCertificate = $signCertificate(
    ['C' => 'US', 'O' => 'PimPoPom Test Intermediate', 'CN' => 'PimPoPom Test Intermediate'],
    $intermediateKey,
    $rootCertificate,
    $rootKey,
    'v3_intermediate',
    2,
);
$gameCenterKey = $newRsaKey();
$gameCenterCertificate = $signCertificate(
    ['C' => 'US', 'O' => 'Apple Inc.', 'CN' => 'Apple Inc.'],
    $gameCenterKey,
    $intermediateCertificate,
    $intermediateKey,
    'v3_game_center',
    3,
);
foreach ([
    'root.pem' => $rootCertificate,
    'intermediate.pem' => $intermediateCertificate,
    'leaf.pem' => $gameCenterCertificate,
] as $filename => $certificate) {
    if (!openssl_x509_export($certificate, $pem)
        || file_put_contents($certificateDirectory . '/' . $filename, $pem) === false
    ) {
        throw new RuntimeException('Could not export a Game Center test certificate.');
    }
}
if (!openssl_pkey_export($gameCenterKey, $gameCenterPrivatePem)) {
    throw new RuntimeException('Could not export the Game Center verifier test key.');
}
$gameCenterLeafPem = file_get_contents($certificateDirectory . '/leaf.pem');
if (!is_string($gameCenterLeafPem)) {
    throw new RuntimeException('Could not read the Game Center leaf certificate.');
}
$clockMilliseconds = (int) floor(microtime(true) * 1_000);
$teamPlayerId = 'T:1234567890';
$bundleId = 'com.otcsoftware.pimpopom';
$timestamp = $clockMilliseconds - 500;
$challengeIssuedAtMilliseconds = $clockMilliseconds - 1_000;
$saltBytes = random_bytes(32);
$timestampBytes = pack(
    'N2',
    intdiv($timestamp, 4_294_967_296),
    $timestamp % 4_294_967_296,
);
$signedBytes = $teamPlayerId . $bundleId . $timestampBytes . $saltBytes;
if (!openssl_sign($signedBytes, $signatureBytes, $gameCenterPrivatePem, OPENSSL_ALGO_SHA256)) {
    throw new RuntimeException('Could not sign the Game Center test tuple.');
}
$trustedUrl = 'https://static.gc.apple.com/public-key/gc-test.cer';
$keyFetches = 0;
$gameCenterVerifier = new GameCenterIdentityVerifier(
    $bundleId,
    ['static.gc.apple.com'],
    [$certificateDirectory . '/root.pem'],
    $certificateDirectory . '/intermediate.pem',
    static function (string $url) use ($trustedUrl, $gameCenterLeafPem, &$keyFetches): string {
        if ($url !== $trustedUrl) {
            throw new RuntimeException('Unexpected Game Center key URL.');
        }
        $keyFetches++;
        return $gameCenterLeafPem;
    },
    static fn (): int => $clockMilliseconds,
);
$gameCenterIdentity = $gameCenterVerifier->verify(
    $teamPlayerId,
    $trustedUrl,
    base64_encode($signatureBytes),
    base64_encode($saltBytes),
    $timestamp,
    $challengeIssuedAtMilliseconds,
);
$assert($gameCenterIdentity->teamPlayerId === $teamPlayerId, 'The signed teamPlayerID is accepted.');
$assert(strlen($gameCenterIdentity->assertionHash) === 32, 'Game Center proof gets a replay digest.');
$assert($keyFetches === 1, 'Game Center key is fetched only after URL and freshness validation.');
$throwsStatus(
    401,
    static fn () => $gameCenterVerifier->verify(
        'T:mutated',
        $trustedUrl,
        base64_encode($signatureBytes),
        base64_encode($saltBytes),
        $timestamp,
        $challengeIssuedAtMilliseconds,
    ),
    'Changing the signed Game Center teamPlayerID invalidates the proof.',
);
$throwsStatus(
    401,
    static fn () => $gameCenterVerifier->verify(
        $teamPlayerId,
        $trustedUrl,
        base64_encode($signatureBytes),
        base64_encode($saltBytes),
        $clockMilliseconds - 300_001,
        $challengeIssuedAtMilliseconds,
    ),
    'Stale Game Center proof is rejected before key fetch.',
);
$throwsStatus(
    400,
    static fn () => $gameCenterVerifier->verify(
        $teamPlayerId,
        'https://attacker.example/public-key/fake.cer',
        base64_encode($signatureBytes),
        base64_encode($saltBytes),
        $timestamp,
        $challengeIssuedAtMilliseconds,
    ),
    'A non-Apple Game Center public-key host is rejected.',
);
$throwsStatus(
    400,
    static fn () => $gameCenterVerifier->verify(
        $teamPlayerId,
        'https://static.gc.apple.com@attacker.example/public-key/fake.cer',
        base64_encode($signatureBytes),
        base64_encode($saltBytes),
        $timestamp,
        $challengeIssuedAtMilliseconds,
    ),
    'Game Center public-key URL userinfo cannot bypass host checks.',
);
$throwsStatus(
    401,
    static fn () => $gameCenterVerifier->verify(
        $teamPlayerId,
        $trustedUrl,
        base64_encode($signatureBytes),
        base64_encode($saltBytes),
        $timestamp,
        $clockMilliseconds + 31_000,
    ),
    'A Game Center proof created before the current link challenge is rejected.',
);
$untrustedChainVerifier = new GameCenterIdentityVerifier(
    $bundleId,
    ['static.gc.apple.com'],
    [$certificateDirectory . '/root.pem'],
    $certificateDirectory . '/root.pem',
    static fn (string $url): string => $gameCenterLeafPem,
    static fn (): int => $clockMilliseconds,
);
$throwsStatus(
    503,
    static fn () => $untrustedChainVerifier->verify(
        $teamPlayerId,
        $trustedUrl,
        base64_encode($signatureBytes),
        base64_encode($saltBytes),
        $timestamp,
        $challengeIssuedAtMilliseconds,
    ),
    'A Game Center leaf without its trusted intermediate chain is rejected.',
);

fwrite(STDOUT, "Identity verifier checks passed ({$assertions} assertions).\n");
