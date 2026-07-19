<?php

declare(strict_types=1);

namespace SpeedyTapper;

use JsonException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Offline verifier for Apple's compact ES256 JWS payloads.
 *
 * Trust roots are injected so production can pin Apple's published roots and
 * tests can use an isolated chain. This verifier intentionally makes no OCSP
 * or other online revocation claim. Callers must validate the verified payload
 * fields (bundle, environment, product, transaction, and account token).
 */
final class AppleJwsVerifier
{
    public const APPLE_LEAF_OID = '1.2.840.113635.100.6.11.1';
    public const APPLE_INTERMEDIATE_OID = '1.2.840.113635.100.6.2.1';

    private const MAX_COMPACT_JWS_BYTES = 2_000_000;
    private const MAX_CERTIFICATE_DER_BYTES = 32_768;
    private const ES256_JOSE_SIGNATURE_BYTES = 64;

    /** @var array<string, true> */
    private array $trustedRootFingerprints = [];

    /**
     * @param list<string> $trustedRootPemCertificates
     */
    public function __construct(array $trustedRootPemCertificates)
    {
        if ($trustedRootPemCertificates === []) {
            throw new AppleJwsVerificationException('At least one pinned Apple root certificate is required.');
        }

        foreach ($trustedRootPemCertificates as $rootPem) {
            if (!is_string($rootPem) || trim($rootPem) === '') {
                throw new AppleJwsVerificationException('Pinned root certificates must be non-empty PEM strings.');
            }

            $root = openssl_x509_read($rootPem);
            if (!$root instanceof OpenSSLCertificate) {
                throw new AppleJwsVerificationException('A pinned root certificate could not be parsed.');
            }

            $rootDer = self::pemCertificateToDer($rootPem);
            $this->trustedRootFingerprints[hash('sha256', $rootDer)] = true;
        }
    }

    /**
     * @param list<string> $paths
     */
    public static function fromPemFiles(array $paths): self
    {
        $certificates = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                throw new AppleJwsVerificationException('Pinned root paths must be non-empty strings.');
            }

            $contents = @file_get_contents($path);
            if (!is_string($contents) || $contents === '') {
                throw new AppleJwsVerificationException('A pinned root certificate could not be read.');
            }
            $certificates[] = $contents;
        }

        return new self($certificates);
    }

    /**
     * Verify a compact Apple JWS and return its decoded payload object.
     *
     * Certificate validity defaults to the authenticated payload's signedDate,
     * matching Apple's offline-verification model for historical transactions.
     * Tests or narrowly scoped callers may provide an explicit epoch time in
     * milliseconds.
     *
     * @return array<string, mixed>
     */
    public function verify(string $compactJws, ?int $effectiveTimeMs = null): array
    {
        if ($compactJws === '' || strlen($compactJws) > self::MAX_COMPACT_JWS_BYTES) {
            throw new AppleJwsVerificationException('The Apple signed payload has an invalid size.');
        }

        $parts = explode('.', $compactJws);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new AppleJwsVerificationException('The Apple signed payload must contain exactly three compact JWS parts.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $headerJson = self::decodeBase64Url($encodedHeader, 'JWS header');
        $payloadJson = self::decodeBase64Url($encodedPayload, 'JWS payload');
        $signature = self::decodeBase64Url($encodedSignature, 'JWS signature');

        $header = self::decodeJsonObject($headerJson, 'JWS header');
        $payload = self::decodeJsonObject($payloadJson, 'JWS payload');

        if (($header['alg'] ?? null) !== 'ES256') {
            throw new AppleJwsVerificationException('The Apple signed payload must use ES256.');
        }
        if (array_key_exists('crit', $header) || array_key_exists('b64', $header)) {
            throw new AppleJwsVerificationException('Unsupported critical JWS header parameters were supplied.');
        }

        $x5c = $header['x5c'] ?? null;
        if (!is_array($x5c) || !array_is_list($x5c) || count($x5c) !== 3) {
            throw new AppleJwsVerificationException('The Apple x5c chain must contain exactly three certificates.');
        }

        $certificates = [];
        $certificateDer = [];
        foreach ($x5c as $encodedCertificate) {
            if (!is_string($encodedCertificate) || $encodedCertificate === '') {
                throw new AppleJwsVerificationException('Every Apple x5c certificate must be a non-empty base64 string.');
            }

            $der = base64_decode($encodedCertificate, true);
            if (!is_string($der)
                || $der === ''
                || strlen($der) > self::MAX_CERTIFICATE_DER_BYTES
                || base64_encode($der) !== $encodedCertificate
            ) {
                throw new AppleJwsVerificationException('An Apple x5c certificate is not canonical base64 DER.');
            }

            $certificate = openssl_x509_read(self::derCertificateToPem($der));
            if (!$certificate instanceof OpenSSLCertificate) {
                throw new AppleJwsVerificationException('An Apple x5c certificate could not be parsed.');
            }

            $certificates[] = $certificate;
            $certificateDer[] = $der;
        }

        [$leaf, $intermediate, $root] = $certificates;
        $rootDer = $certificateDer[2];

        if (!isset($this->trustedRootFingerprints[hash('sha256', $rootDer)])) {
            throw new AppleJwsVerificationException('The Apple certificate chain does not end at a pinned root.');
        }

        $leafDetails = self::parseCertificate($leaf, 'leaf');
        $intermediateDetails = self::parseCertificate($intermediate, 'intermediate');
        $rootDetails = self::parseCertificate($root, 'root');

        self::requireCertificateOid($leafDetails, self::APPLE_LEAF_OID, 'leaf');
        self::requireEndEntityCertificate($leafDetails);
        self::requireCertificateOid(
            $intermediateDetails,
            self::APPLE_INTERMEDIATE_OID,
            'intermediate',
        );
        self::requireCertificateAuthority($intermediateDetails, 'intermediate');
        self::requireCertificateAuthority($rootDetails, 'root');
        self::requireIssuer($leafDetails, $intermediateDetails, 'leaf');
        self::requireIssuer($intermediateDetails, $rootDetails, 'intermediate');
        self::requireIssuer($rootDetails, $rootDetails, 'root');

        $intermediateKey = self::certificatePublicKey($intermediate, 'intermediate');
        $rootKey = self::certificatePublicKey($root, 'root');
        if (openssl_x509_verify($leaf, $intermediateKey) !== 1
            || openssl_x509_verify($intermediate, $rootKey) !== 1
            || openssl_x509_verify($root, $rootKey) !== 1
        ) {
            throw new AppleJwsVerificationException('The Apple certificate chain signature is invalid.');
        }

        $leafKey = self::certificatePublicKey($leaf, 'leaf');
        self::requireP256Key($leafKey);

        if (strlen($signature) !== self::ES256_JOSE_SIGNATURE_BYTES) {
            throw new AppleJwsVerificationException('The ES256 JWS signature must be exactly 64 bytes.');
        }

        $derSignature = self::joseEs256SignatureToDer($signature);
        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $derSignature,
            $leafKey,
            OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            throw new AppleJwsVerificationException('The Apple JWS signature is invalid.');
        }

        $verificationTimeMs = $effectiveTimeMs ?? ($payload['signedDate'] ?? null);
        if (!is_int($verificationTimeMs) || $verificationTimeMs <= 0) {
            throw new AppleJwsVerificationException('The verified Apple payload must contain an integer signedDate.');
        }
        $verificationTime = intdiv($verificationTimeMs, 1_000);
        self::requireCertificateValidAt($leafDetails, $verificationTime, 'leaf');
        self::requireCertificateValidAt($intermediateDetails, $verificationTime, 'intermediate');
        self::requireCertificateValidAt($rootDetails, $verificationTime, 'root');

        return $payload;
    }

    private static function decodeBase64Url(string $value, string $label): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new AppleJwsVerificationException("The {$label} is not canonical base64url.");
        }

        $paddingLength = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $paddingLength), true);
        if (!is_string($decoded) || self::encodeBase64Url($decoded) !== $value) {
            throw new AppleJwsVerificationException("The {$label} is not canonical base64url.");
        }

        return $decoded;
    }

    private static function encodeBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private static function decodeJsonObject(string $json, string $label): array
    {
        $trimmed = trim($json);
        if (!str_starts_with($trimmed, '{') || !str_ends_with($trimmed, '}')) {
            throw new AppleJwsVerificationException("The {$label} must be a JSON object.");
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new AppleJwsVerificationException("The {$label} is invalid JSON.", 0, $error);
        }

        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new AppleJwsVerificationException("The {$label} must be a JSON object.");
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function parseCertificate(OpenSSLCertificate $certificate, string $label): array
    {
        $details = openssl_x509_parse($certificate, false);
        if (!is_array($details)) {
            throw new AppleJwsVerificationException("The Apple {$label} certificate could not be inspected.");
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function requireCertificateOid(array $details, string $oid, string $label): void
    {
        $extensions = $details['extensions'] ?? [];
        if (!is_array($extensions) || !array_key_exists($oid, $extensions)) {
            throw new AppleJwsVerificationException("The Apple {$label} certificate is missing required OID {$oid}.");
        }
    }

    /** @param array<string, mixed> $details */
    private static function requireCertificateAuthority(array $details, string $label): void
    {
        $basicConstraints = $details['extensions']['basicConstraints'] ?? null;
        if (!is_string($basicConstraints) || !preg_match('/(?:^|,\s*)CA\s*:\s*TRUE(?:,|$)/i', $basicConstraints)) {
            throw new AppleJwsVerificationException("The Apple {$label} certificate is not a certificate authority.");
        }

        $keyUsage = $details['extensions']['keyUsage'] ?? null;
        if (is_string($keyUsage) && stripos($keyUsage, 'Certificate Sign') === false) {
            throw new AppleJwsVerificationException("The Apple {$label} certificate cannot sign certificates.");
        }
    }

    /** @param array<string, mixed> $details */
    private static function requireEndEntityCertificate(array $details): void
    {
        $basicConstraints = $details['extensions']['basicConstraints'] ?? null;
        if (!is_string($basicConstraints) || !preg_match('/(?:^|,\s*)CA\s*:\s*FALSE(?:,|$)/i', $basicConstraints)) {
            throw new AppleJwsVerificationException('The Apple leaf certificate is not an end-entity certificate.');
        }

        $keyUsage = $details['extensions']['keyUsage'] ?? null;
        if (!is_string($keyUsage) || stripos($keyUsage, 'Digital Signature') === false) {
            throw new AppleJwsVerificationException('The Apple leaf certificate cannot verify digital signatures.');
        }
    }

    /**
     * @param array<string, mixed> $child
     * @param array<string, mixed> $issuer
     */
    private static function requireIssuer(array $child, array $issuer, string $label): void
    {
        $childIssuer = $child['issuer'] ?? null;
        $issuerSubject = $issuer['subject'] ?? null;
        if (!is_array($childIssuer)
            || !is_array($issuerSubject)
            || self::normalizedDistinguishedName($childIssuer) !== self::normalizedDistinguishedName($issuerSubject)
        ) {
            throw new AppleJwsVerificationException("The Apple {$label} certificate issuer does not match its parent.");
        }
    }

    /** @param array<string, mixed> $name */
    private static function normalizedDistinguishedName(array $name): string
    {
        ksort($name, SORT_STRING);
        foreach ($name as &$value) {
            if (is_array($value)) {
                sort($value, SORT_STRING);
            }
        }
        unset($value);

        return json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /** @param array<string, mixed> $details */
    private static function requireCertificateValidAt(array $details, int $timestamp, string $label): void
    {
        $validFrom = $details['validFrom_time_t'] ?? null;
        $validTo = $details['validTo_time_t'] ?? null;
        if (!is_int($validFrom) || !is_int($validTo) || $timestamp < $validFrom || $timestamp > $validTo) {
            throw new AppleJwsVerificationException("The Apple {$label} certificate is not valid at signedDate.");
        }
    }

    private static function certificatePublicKey(
        OpenSSLCertificate $certificate,
        string $label,
    ): OpenSSLAsymmetricKey {
        $key = openssl_pkey_get_public($certificate);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new AppleJwsVerificationException("The Apple {$label} public key could not be read.");
        }

        return $key;
    }

    private static function requireP256Key(OpenSSLAsymmetricKey $key): void
    {
        $details = openssl_pkey_get_details($key);
        $curve = is_array($details) ? ($details['ec']['curve_name'] ?? null) : null;
        if (!is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || !in_array($curve, ['prime256v1', 'secp256r1'], true)
        ) {
            throw new AppleJwsVerificationException('The Apple JWS leaf key must use the P-256 curve.');
        }
    }

    private static function joseEs256SignatureToDer(string $signature): string
    {
        $size = intdiv(strlen($signature), 2);
        $r = self::derInteger(substr($signature, 0, $size));
        $s = self::derInteger(substr($signature, $size));
        $sequence = $r . $s;

        return "\x30" . self::derLength(strlen($sequence)) . $sequence;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        } elseif ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derCertificateToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    private static function pemCertificateToDer(string $pem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----([A-Za-z0-9+\/=\r\n]+)-----END CERTIFICATE-----/D', trim($pem), $matches) !== 1) {
            throw new AppleJwsVerificationException('A pinned root is not a single PEM certificate.');
        }

        $encoded = preg_replace('/\s+/', '', $matches[1]);
        $der = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($der) || $der === '') {
            throw new AppleJwsVerificationException('A pinned root PEM body is invalid.');
        }

        return $der;
    }
}
