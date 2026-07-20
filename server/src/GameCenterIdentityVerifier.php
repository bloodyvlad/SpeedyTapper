<?php

declare(strict_types=1);

namespace SpeedyTapper;

use Closure;

/**
 * Verifies GameKit's detached identity signature for a normal (non-Apple
 * Arcade) game. The signed identity is teamPlayerID. A client-supplied
 * gamePlayerID is deliberately not accepted because it is not covered by this
 * signature contract.
 */
final class GameCenterIdentityVerifier
{
    private const MAX_CERTIFICATE_BYTES = 65_536;
    private const MAX_PAST_AGE_MILLISECONDS = 300_000;
    private const MAX_FUTURE_SKEW_MILLISECONDS = 30_000;

    /** @var list<string> */
    private array $allowedKeyHosts;
    /** @var list<string> */
    private array $trustedRootCertificatePaths;
    /** @var Closure(string): string */
    private Closure $certificateFetcher;
    /** @var Closure(): int */
    private Closure $clockMilliseconds;

    /**
     * @param list<string> $allowedKeyHosts
     * @param list<string> $trustedRootCertificatePaths
     * @param null|callable(string): string $certificateFetcher Returns the leaf certificate in DER or PEM.
     * @param null|callable(): int $clockMilliseconds
     */
    public function __construct(
        private readonly string $bundleId,
        array $allowedKeyHosts = ['static.gc.apple.com'],
        array $trustedRootCertificatePaths = [],
        private readonly string $untrustedCertificateBundlePath = '',
        ?callable $certificateFetcher = null,
        ?callable $clockMilliseconds = null,
    ) {
        if ($this->bundleId === '' || strlen($this->bundleId) > 255) {
            throw new \InvalidArgumentException('Game Center bundle identifier is invalid.');
        }
        $hosts = [];
        foreach ($allowedKeyHosts as $host) {
            if (!is_string($host) || preg_match('/^[a-z0-9.-]{1,253}$/D', $host) !== 1) {
                throw new \InvalidArgumentException('Game Center key host is invalid.');
            }
            $hosts[] = strtolower($host);
        }
        if ($hosts === []) {
            throw new \InvalidArgumentException('At least one Game Center key host is required.');
        }
        foreach ($trustedRootCertificatePaths as $path) {
            if (!is_string($path) || trim($path) === '' || !is_readable(trim($path))) {
                throw new \InvalidArgumentException('Game Center trust root is invalid.');
            }
        }
        if (
            $this->untrustedCertificateBundlePath === ''
            || !is_readable($this->untrustedCertificateBundlePath)
        ) {
            throw new \InvalidArgumentException('Game Center intermediate bundle is invalid.');
        }
        $this->allowedKeyHosts = array_values(array_unique($hosts));
        $this->trustedRootCertificatePaths = array_values($trustedRootCertificatePaths);
        $this->certificateFetcher = $certificateFetcher === null
            ? Closure::fromCallable([$this, 'fetchCertificate'])
            : Closure::fromCallable($certificateFetcher);
        $this->clockMilliseconds = $clockMilliseconds === null
            ? static fn (): int => (int) floor(microtime(true) * 1000)
            : Closure::fromCallable($clockMilliseconds);
    }

    public function verify(
        mixed $teamPlayerId,
        mixed $publicKeyUrl,
        mixed $signature,
        mixed $salt,
        mixed $timestamp,
        mixed $challengeIssuedAtMilliseconds,
    ): GameCenterIdentity {
        if (
            !is_string($teamPlayerId)
            || $teamPlayerId === ''
            || strlen($teamPlayerId) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $teamPlayerId) === 1
        ) {
            throw new ApiException(400, 'Game Center player identity is invalid.');
        }
        if (!is_string($publicKeyUrl)) {
            throw new ApiException(400, 'Game Center public key URL is invalid.');
        }
        $this->validatePublicKeyUrl($publicKeyUrl);
        $signatureBytes = $this->decodeCanonicalBase64($signature, 'signature', 1, 1_024);
        $saltBytes = $this->decodeCanonicalBase64($salt, 'salt', 1, 1_024);
        if (
            !is_int($timestamp)
            || $timestamp < 1
            || !is_int($challengeIssuedAtMilliseconds)
            || $challengeIssuedAtMilliseconds < 1
        ) {
            throw new ApiException(400, 'Game Center timestamp is invalid.');
        }

        $now = ($this->clockMilliseconds)();
        if (
            $timestamp < $now - self::MAX_PAST_AGE_MILLISECONDS
            || $timestamp > $now + self::MAX_FUTURE_SKEW_MILLISECONDS
            || $timestamp < $challengeIssuedAtMilliseconds - self::MAX_FUTURE_SKEW_MILLISECONDS
        ) {
            throw new ApiException(401, 'Game Center proof is stale or not yet valid.');
        }

        $timestampBytes = self::unsignedBigEndian64($timestamp);
        $signedBytes = $teamPlayerId . $this->bundleId . $timestampBytes . $saltBytes;
        $publicKeyPem = $this->validatedPublicKey(($this->certificateFetcher)($publicKeyUrl));
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            throw new ApiException(503, 'Game Center verification key is unavailable.');
        }
        $verified = openssl_verify(
            $signedBytes,
            $signatureBytes,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            throw new ApiException(401, 'Game Center identity could not be verified.');
        }

        $assertionHash = hash(
            'sha256',
            "game_center_assertion\0"
                . $teamPlayerId . "\0"
                . $this->bundleId . "\0"
                . $timestampBytes
                . $saltBytes
                . $signatureBytes,
            true,
        );
        return new GameCenterIdentity($teamPlayerId, $assertionHash, $timestamp);
    }

    private function validatePublicKeyUrl(string $url): void
    {
        if ($url === '' || strlen($url) > 2_048) {
            throw new ApiException(400, 'Game Center public key URL is invalid.');
        }
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)
            || !in_array(strtolower((string) $parts['host']), $this->allowedKeyHosts, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || !is_string($parts['path'] ?? null)
            || !str_starts_with((string) $parts['path'], '/public-key/')
            || !str_ends_with(strtolower((string) $parts['path']), '.cer')
        ) {
            throw new ApiException(400, 'Game Center public key URL is not trusted.');
        }
    }

    private function decodeCanonicalBase64(
        mixed $value,
        string $label,
        int $minimumBytes,
        int $maximumBytes,
    ): string {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > (int) ceil($maximumBytes * 4 / 3) + 4
            || preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $value) !== 1
        ) {
            throw new ApiException(400, 'Game Center ' . $label . ' is invalid.');
        }
        $decoded = base64_decode($value, true);
        if (
            !is_string($decoded)
            || strlen($decoded) < $minimumBytes
            || strlen($decoded) > $maximumBytes
            || !hash_equals(base64_encode($decoded), $value)
        ) {
            throw new ApiException(400, 'Game Center ' . $label . ' is invalid.');
        }
        return $decoded;
    }

    private static function unsignedBigEndian64(int $value): string
    {
        $high = intdiv($value, 4_294_967_296);
        $low = $value % 4_294_967_296;
        return pack('N2', $high, $low);
    }

    private function fetchCertificate(string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new ApiException(503, 'Game Center verification is unavailable on this server.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new ApiException(503, 'Game Center verification key is unavailable.');
        }
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PimPoPom-GameCenter/1',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_CERTIFICATE_BYTES) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $success = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
        if ($success !== true || $status !== 200 || $body === '') {
            throw new ApiException(503, 'Game Center verification key is unavailable.');
        }

        return $body;
    }

    private function validatedPublicKey(string $certificateBytes): string
    {
        if ($certificateBytes === '' || strlen($certificateBytes) > self::MAX_CERTIFICATE_BYTES) {
            throw new ApiException(503, 'Game Center verification certificate is invalid.');
        }
        $certificatePem = str_contains($certificateBytes, '-----BEGIN CERTIFICATE-----')
            ? $certificateBytes
            : "-----BEGIN CERTIFICATE-----\n"
                . chunk_split(base64_encode($certificateBytes), 64, "\n")
                . "-----END CERTIFICATE-----\n";
        $certificate = openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new ApiException(503, 'Game Center verification certificate is invalid.');
        }
        $trustResult = openssl_x509_checkpurpose(
            $certificate,
            X509_PURPOSE_ANY,
            $this->trustedRootCertificatePaths,
            $this->untrustedCertificateBundlePath,
        );
        if ($trustResult !== true && $trustResult !== 1) {
            throw new ApiException(503, 'Game Center verification certificate is not trusted.');
        }
        // Use OpenSSL's short DN names so the Apple subject is read as O/CN.
        $details = openssl_x509_parse($certificate, true);
        $now = intdiv(($this->clockMilliseconds)(), 1_000);
        if (
            !is_array($details)
            || !is_int($details['validFrom_time_t'] ?? null)
            || !is_int($details['validTo_time_t'] ?? null)
            || $details['validFrom_time_t'] > $now
            || $details['validTo_time_t'] < $now
            || ($details['subject']['O'] ?? null) !== 'Apple Inc.'
            || ($details['subject']['CN'] ?? null) !== 'Apple Inc.'
            || !str_contains((string) ($details['extensions']['keyUsage'] ?? ''), 'Digital Signature')
            || !str_contains((string) ($details['extensions']['extendedKeyUsage'] ?? ''), 'Code Signing')
            || str_contains((string) ($details['extensions']['basicConstraints'] ?? ''), 'CA:TRUE')
        ) {
            throw new ApiException(503, 'Game Center verification certificate is not an active Apple signing certificate.');
        }
        $publicKey = openssl_pkey_get_public($certificate);
        $keyDetails = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        if (
            !is_array($keyDetails)
            || ($keyDetails['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || (int) ($keyDetails['bits'] ?? 0) < 2_048
            || !is_string($keyDetails['key'] ?? null)
        ) {
            throw new ApiException(503, 'Game Center verification key is invalid.');
        }
        return $keyDetails['key'];
    }
}
