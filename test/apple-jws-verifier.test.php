<?php

declare(strict_types=1);

use SpeedyTapper\AppleJwsVerificationException;
use SpeedyTapper\AppleJwsVerifier;

require dirname(__DIR__) . '/server/autoload.php';

$fixtureDirectory = __DIR__ . '/fixtures/apple-jws';
$readFixture = static function (string $name) use ($fixtureDirectory): string {
    $contents = file_get_contents($fixtureDirectory . '/' . $name);
    if (!is_string($contents) || $contents === '') {
        throw new RuntimeException("Missing Apple JWS test fixture: {$name}");
    }

    return $contents;
};

$root = $readFixture('root.pem');
$intermediate = $readFixture('intermediate.pem');
$leaf = $readFixture('leaf.pem');
$leafKey = $readFixture('leaf-key.pem');
$leafWithoutOid = $readFixture('leaf-no-oid.pem');
$leafWithoutOidKey = $readFixture('leaf-no-oid-key.pem');
$intermediateWithoutOid = $readFixture('intermediate-no-oid.pem');
$leafUnderIntermediateWithoutOid = $readFixture('leaf-under-no-oid.pem');
$leafUnderIntermediateWithoutOidKey = $readFixture('leaf-under-no-oid-key.pem');

$base64Url = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

$certificateDer = static function (string $pem): string {
    if (preg_match('/-----BEGIN CERTIFICATE-----([A-Za-z0-9+\/=\r\n]+)-----END CERTIFICATE-----/D', trim($pem), $matches) !== 1) {
        throw new RuntimeException('The test certificate is not valid PEM.');
    }
    $encoded = preg_replace('/\s+/', '', $matches[1]);
    $der = is_string($encoded) ? base64_decode($encoded, true) : false;
    if (!is_string($der)) {
        throw new RuntimeException('The test certificate cannot be decoded.');
    }

    return $der;
};

$derSignatureToJose = static function (string $der): string {
    $offset = 0;
    $readLength = static function () use ($der, &$offset): int {
        if ($offset >= strlen($der)) {
            throw new RuntimeException('Truncated DER signature length.');
        }
        $first = ord($der[$offset++]);
        if (($first & 0x80) === 0) {
            return $first;
        }
        $byteCount = $first & 0x7f;
        if ($byteCount < 1 || $byteCount > 2 || $offset + $byteCount > strlen($der)) {
            throw new RuntimeException('Unsupported DER signature length.');
        }
        $length = 0;
        for ($index = 0; $index < $byteCount; $index++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }

        return $length;
    };

    if (($der[$offset++] ?? '') !== "\x30") {
        throw new RuntimeException('The test signature is not a DER sequence.');
    }
    $sequenceLength = $readLength();
    if ($sequenceLength !== strlen($der) - $offset) {
        throw new RuntimeException('The test DER sequence has the wrong length.');
    }

    $integers = [];
    for ($index = 0; $index < 2; $index++) {
        if (($der[$offset++] ?? '') !== "\x02") {
            throw new RuntimeException('The test DER sequence does not contain two integers.');
        }
        $integerLength = $readLength();
        $integer = substr($der, $offset, $integerLength);
        $offset += $integerLength;
        $integer = ltrim($integer, "\x00");
        if (strlen($integer) > 32) {
            throw new RuntimeException('The test ECDSA integer is too large.');
        }
        $integers[] = str_pad($integer, 32, "\x00", STR_PAD_LEFT);
    }

    if ($offset !== strlen($der)) {
        throw new RuntimeException('The test DER signature contains trailing bytes.');
    }

    return $integers[0] . $integers[1];
};

$makeJws = static function (
    array $payload,
    string $signingCertificate,
    string $privateKey,
    array $chain,
    array $headerOverrides = [],
) use ($base64Url, $certificateDer, $derSignatureToJose): string {
    $header = array_replace([
        'alg' => 'ES256',
        'x5c' => array_map(
            static fn (string $certificate): string => base64_encode($certificateDer($certificate)),
            [$signingCertificate, ...$chain],
        ),
    ], $headerOverrides);

    $headerJson = json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $signingInput = $base64Url($headerJson) . '.' . $base64Url($payloadJson);
    $derSignature = '';
    if (!openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The test JWS could not be signed.');
    }

    return $signingInput . '.' . $base64Url($derSignatureToJose($derSignature));
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$rejects = static function (callable $callback, string $expectedMessage, string $message) use ($assert): void {
    try {
        $callback();
    } catch (AppleJwsVerificationException $error) {
        $assert(
            str_contains($error->getMessage(), $expectedMessage),
            $message . ' Unexpected error: ' . $error->getMessage(),
        );
        return;
    }

    $assert(false, $message);
};

$payload = [
    'signedDate' => (int) floor(microtime(true) * 1_000),
    'transactionId' => '2000000123456789',
    'productId' => 'com.otcsoftware.pimpopom.coins.small',
    'bundleId' => 'com.otcsoftware.pimpopom',
    'environment' => 'Sandbox',
];
$jws = $makeJws($payload, $leaf, $leafKey, [$intermediate, $root]);
$verifier = new AppleJwsVerifier([$root]);
$verified = $verifier->verify($jws);
$assert($verified === $payload, 'A valid pinned ES256 Apple-style JWS returns the authenticated payload.');

$fileVerifier = AppleJwsVerifier::fromPemFiles([$fixtureDirectory . '/root.pem']);
$assert(
    $fileVerifier->verify($jws)['transactionId'] === $payload['transactionId'],
    'Pinned roots can be loaded from explicit PEM paths.',
);

$rejects(
    static fn () => (new AppleJwsVerifier([])),
    'At least one pinned',
    'An empty trust store is rejected.',
);
$rejects(
    static fn () => $verifier->verify($jws . '.extra'),
    'exactly three',
    'Compact JWS input must have exactly three parts.',
);

$wrongAlgorithm = $makeJws(
    $payload,
    $leaf,
    $leafKey,
    [$intermediate, $root],
    ['alg' => 'HS256'],
);
$rejects(
    static fn () => $verifier->verify($wrongAlgorithm),
    'must use ES256',
    'Non-ES256 algorithm headers are rejected before payload acceptance.',
);

$shortChain = $makeJws($payload, $leaf, $leafKey, [$intermediate], ['x5c' => [
    base64_encode($certificateDer($leaf)),
    base64_encode($certificateDer($intermediate)),
]]);
$rejects(
    static fn () => $verifier->verify($shortChain),
    'exactly three certificates',
    'Apple x5c chains must contain exactly three certificates.',
);

$criticalHeader = $makeJws(
    $payload,
    $leaf,
    $leafKey,
    [$intermediate, $root],
    ['crit' => ['custom']],
);
$rejects(
    static fn () => $verifier->verify($criticalHeader),
    'Unsupported critical',
    'Unsupported critical header processing is rejected fail-closed.',
);

$untrustedVerifier = AppleJwsVerifier::fromPemFiles([
    dirname(__DIR__) . '/server/certs/AppleRootCA-G3.pem',
]);
$rejects(
    static fn () => $untrustedVerifier->verify($jws),
    'pinned root',
    'A valid signature chain is rejected when its root is not pinned.',
);

$missingOidJws = $makeJws(
    $payload,
    $leafWithoutOid,
    $leafWithoutOidKey,
    [$intermediate, $root],
);
$rejects(
    static fn () => $verifier->verify($missingOidJws),
    AppleJwsVerifier::APPLE_LEAF_OID,
    'The App Store leaf certificate OID is mandatory.',
);

$missingIntermediateOidJws = $makeJws(
    $payload,
    $leafUnderIntermediateWithoutOid,
    $leafUnderIntermediateWithoutOidKey,
    [$intermediateWithoutOid, $root],
);
$rejects(
    static fn () => $verifier->verify($missingIntermediateOidJws),
    AppleJwsVerifier::APPLE_INTERMEDIATE_OID,
    'The App Store intermediate certificate OID is mandatory.',
);

[$signedHeader, $signedPayload, $signedSignature] = explode('.', $jws);
$signatureBytes = base64_decode(
    strtr($signedSignature, '-_', '+/') . str_repeat('=', (4 - strlen($signedSignature) % 4) % 4),
    true,
);
if (!is_string($signatureBytes)) {
    throw new RuntimeException('Could not decode the test signature.');
}
$signatureBytes[0] = chr(ord($signatureBytes[0]) ^ 0x01);
$tamperedSignature = $signedHeader . '.' . $signedPayload . '.' . $base64Url($signatureBytes);
$rejects(
    static fn () => $verifier->verify($tamperedSignature),
    'signature is invalid',
    'A one-bit JWS signature change is rejected.',
);

$shortSignature = $signedHeader . '.' . $signedPayload . '.' . $base64Url(substr($signatureBytes, 0, 63));
$rejects(
    static fn () => $verifier->verify($shortSignature),
    'exactly 64 bytes',
    'ES256 JOSE signatures must have the fixed 64-byte form.',
);

$rejects(
    static fn () => $verifier->verify($jws, 1_000),
    'not valid at signedDate',
    'Certificate validity is checked at the authenticated effective time.',
);

$noSignedDatePayload = $payload;
unset($noSignedDatePayload['signedDate']);
$noSignedDateJws = $makeJws($noSignedDatePayload, $leaf, $leafKey, [$intermediate, $root]);
$rejects(
    static fn () => $verifier->verify($noSignedDateJws),
    'integer signedDate',
    'Offline verification requires an authenticated integer signedDate.',
);

$appleRoots = AppleJwsVerifier::fromPemFiles([
    dirname(__DIR__) . '/server/certs/AppleRootCA-G2.pem',
    dirname(__DIR__) . '/server/certs/AppleRootCA-G3.pem',
]);
$assert($appleRoots instanceof AppleJwsVerifier, 'Apple-published G2 and G3 root PEMs parse into the pinned store.');
$assert(
    strtoupper(hash('sha256', $certificateDer(file_get_contents(dirname(__DIR__) . '/server/certs/AppleRootCA-G2.pem'))))
        === 'C2B9B042DD57830E7D117DAC55AC8AE19407D38E41D88F3215BC3A890444A050',
    'The pinned Apple Root CA G2 matches Apple\'s published SHA-256 fingerprint.',
);
$assert(
    strtoupper(hash('sha256', $certificateDer(file_get_contents(dirname(__DIR__) . '/server/certs/AppleRootCA-G3.pem'))))
        === '63343ABFB89A6A03EBB57E9B3F5FA7BE7C4F5C756F3017B3A8C488C3653E9179',
    'The pinned Apple Root CA G3 matches Apple\'s published SHA-256 fingerprint.',
);

fwrite(STDOUT, "Apple JWS verifier tests passed ({$assertions} assertions).\n");
