<?php

declare(strict_types=1);

namespace SpeedyTapper;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;

/**
 * Verifies native Sign in with Apple identity tokens against Apple's fixed
 * JWKS origin. This trust path is intentionally separate from StoreKit's
 * ES256/x5c transaction and notification verifier.
 */
final class AppleSignInIdentityVerifier implements AppleIdentityVerifier
{
    private const ISSUER = 'https://appleid.apple.com';
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const MAX_TOKEN_BYTES = 12_288;
    private const MAX_JWKS_BYTES = 65_536;
    private const CLOCK_SKEW_SECONDS = 60;
    private const SHARED_JWKS_TTL_SECONDS = 300;

    /** @var list<string> */
    private array $allowedAudiences;
    /** @var Closure(): array<string, mixed> */
    private Closure $jwksFetcher;
    /** @var Closure(): array<string, mixed> */
    private Closure $jwksDownloader;
    /** @var Closure(): int */
    private Closure $clock;
    /** @var null|array<string, mixed> */
    private ?array $requestJwks = null;
    private bool $forcedJwksRefreshUsed = false;
    private ?string $jwksCachePath;

    /**
     * @param list<string> $allowedAudiences
     * @param null|callable(): array<string, mixed> $jwksFetcher
     * @param null|callable(): int $clock
     */
    public function __construct(
        array $allowedAudiences,
        ?callable $jwksFetcher = null,
        ?callable $clock = null,
        ?string $jwksCachePath = null,
    ) {
        $normalized = [];
        foreach ($allowedAudiences as $audience) {
            if (
                !is_string($audience)
                || trim($audience) === ''
                || strlen(trim($audience)) > 255
            ) {
                throw new \InvalidArgumentException('Apple Sign in audience is invalid.');
            }
            $normalized[] = trim($audience);
        }
        $this->allowedAudiences = array_values(array_unique($normalized));
        if ($this->allowedAudiences === []) {
            throw new \InvalidArgumentException('At least one Apple Sign in audience is required.');
        }
        $this->jwksCachePath = $jwksCachePath;
        $this->jwksDownloader = $jwksFetcher === null
            ? Closure::fromCallable([$this, 'downloadAppleJwks'])
            : Closure::fromCallable($jwksFetcher);
        $this->jwksFetcher = Closure::fromCallable([$this, 'cachedAppleJwks']);
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
    }

    public function verify(
        string $identityToken,
        string $expectedNonce,
        string $expectedAudience,
    ): AppleIdentity {
        if (
            $identityToken === ''
            || strlen($identityToken) > self::MAX_TOKEN_BYTES
            || substr_count($identityToken, '.') !== 2
        ) {
            throw new ApiException(400, 'Apple identity token is invalid.');
        }
        if (
            strlen($expectedNonce) !== 43
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $expectedNonce) !== 1
        ) {
            throw new ApiException(401, 'Apple sign-in challenge is invalid or expired.');
        }
        if (!in_array($expectedAudience, $this->allowedAudiences, true)) {
            throw new ApiException(401, 'Apple sign-in audience is not accepted.');
        }

        [$encodedHeader] = explode('.', $identityToken, 2);
        $header = $this->decodeJsonSegment($encodedHeader, 'header');
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new ApiException(401, 'Apple identity token uses an unsupported algorithm.');
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $kid) !== 1) {
            throw new ApiException(401, 'Apple identity token key is invalid.');
        }
        foreach (['crit', 'jku', 'x5u', 'jwk', 'b64'] as $unsupportedHeader) {
            if (array_key_exists($unsupportedHeader, $header)) {
                throw new ApiException(401, 'Apple identity token header is unsupported.');
            }
        }
        if (isset($header['typ']) && $header['typ'] !== 'JWT') {
            throw new ApiException(401, 'Apple identity token type is invalid.');
        }

        $matchingKey = $this->matchingJwk($kid);
        $now = ($this->clock)();
        $previousTimestamp = JWT::$timestamp;
        $previousLeeway = JWT::$leeway;
        try {
            JWT::$timestamp = $now;
            JWT::$leeway = self::CLOCK_SKEW_SECONDS;
            $keys = JWK::parseKeySet(['keys' => [$matchingKey]], 'RS256');
            $key = $keys[$kid] ?? null;
            if ($key === null) {
                throw new \UnexpectedValueException('Apple signing key could not be parsed.');
            }
            $claims = (array) JWT::decode($identityToken, $key);
        } catch (ApiException $error) {
            throw $error;
        } catch (Throwable) {
            throw new ApiException(401, 'Apple sign-in could not be verified.');
        } finally {
            JWT::$timestamp = $previousTimestamp;
            JWT::$leeway = $previousLeeway;
        }

        $issuer = $claims['iss'] ?? null;
        $audience = $claims['aud'] ?? null;
        $subject = $claims['sub'] ?? null;
        $nonce = $claims['nonce'] ?? null;
        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        if (!is_string($issuer) || !hash_equals(self::ISSUER, $issuer)) {
            throw new ApiException(401, 'Apple identity token issuer is invalid.');
        }
        if (!is_string($audience) || !hash_equals($expectedAudience, $audience)) {
            throw new ApiException(401, 'Apple identity token audience is invalid.');
        }
        if (
            !is_string($subject)
            || $subject === ''
            || strlen($subject) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1
        ) {
            throw new ApiException(401, 'Apple identity token subject is invalid.');
        }
        if (!is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
            throw new ApiException(401, 'Apple sign-in challenge does not match.');
        }
        if (
            !is_int($issuedAt)
            || !is_int($expiresAt)
            || $expiresAt <= $issuedAt
            || $issuedAt > $now + self::CLOCK_SKEW_SECONDS
            || $expiresAt <= $now - self::CLOCK_SKEW_SECONDS
        ) {
            throw new ApiException(401, 'Apple identity token timing is invalid.');
        }

        return new AppleIdentity($subject, $audience);
    }

    /** @return array<string, mixed> */
    private function matchingJwk(string $kid): array
    {
        $jwks = $this->requestJwks ??= ($this->jwksFetcher)();
        $matches = $this->matchingKeys($jwks, $kid);
        if ($matches === [] && !$this->forcedJwksRefreshUsed) {
            // A still-fresh shared cache can legitimately predate an Apple key
            // rotation. Bypass it once for an unknown kid, while bounding an
            // attacker-controlled token to one outbound refresh per request.
            $this->forcedJwksRefreshUsed = true;
            $jwks = ($this->jwksDownloader)();
            $this->requestJwks = $jwks;
            $matches = $this->matchingKeys($jwks, $kid);
        }
        if (count($matches) !== 1) {
            throw new ApiException(401, 'Apple identity token key is unknown.');
        }
        return $matches[0];
    }

    /** @param array<string, mixed> $jwks @return list<array<string, mixed>> */
    private function matchingKeys(array $jwks, string $kid): array
    {
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys) || count($keys) > 32) {
            throw new ApiException(503, 'Apple sign-in keys are temporarily unavailable.');
        }
        $matches = [];
        foreach ($keys as $key) {
            if (!is_array($key) || ($key['kid'] ?? null) !== $kid) {
                continue;
            }
            if (
                ($key['kty'] ?? null) !== 'RSA'
                || ($key['alg'] ?? null) !== 'RS256'
                || ($key['use'] ?? null) !== 'sig'
                || !is_string($key['n'] ?? null)
                || !is_string($key['e'] ?? null)
            ) {
                throw new ApiException(503, 'Apple sign-in key data is invalid.');
            }
            $matches[] = $key;
        }
        return $matches;
    }

    /** @return array<string, mixed> */
    private function decodeJsonSegment(string $encoded, string $label): array
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            throw new ApiException(401, 'Apple identity token ' . $label . ' is invalid.');
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded)) {
            throw new ApiException(401, 'Apple identity token ' . $label . ' is invalid.');
        }
        try {
            $value = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(401, 'Apple identity token ' . $label . ' is invalid.');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new ApiException(401, 'Apple identity token ' . $label . ' is invalid.');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function cachedAppleJwks(): array
    {
        $path = $this->jwksCachePath;
        if (!is_string($path) || $path === '') {
            return ($this->jwksDownloader)();
        }
        $directory = dirname($path);
        if (!$this->privateCacheDirectoryIsSafe($directory)) {
            return ($this->jwksDownloader)();
        }
        clearstatcache(true, $path);
        if (is_link($path)) {
            return ($this->jwksDownloader)();
        }
        if (is_file($path)) {
            $mode = fileperms($path);
            if ($mode === false || ($mode & 0o077) !== 0) {
                return ($this->jwksDownloader)();
            }
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            return ($this->jwksDownloader)();
        }
        try {
            rewind($handle);
            $stored = stream_get_contents($handle, self::MAX_JWKS_BYTES + 4_096);
            if (is_string($stored) && $stored !== '') {
                try {
                    $cached = json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $cached = null;
                }
                $now = ($this->clock)();
                if (
                    is_array($cached)
                    && ($cached['version'] ?? null) === 1
                    && is_int($cached['fetchedAt'] ?? null)
                    && $cached['fetchedAt'] <= $now
                    && $cached['fetchedAt'] >= $now - self::SHARED_JWKS_TTL_SECONDS
                    && is_array($cached['jwks'] ?? null)
                ) {
                    return $cached['jwks'];
                }
            }
            $jwks = ($this->jwksDownloader)();
            $encoded = json_encode([
                'version' => 1,
                'fetchedAt' => ($this->clock)(),
                'jwks' => $jwks,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
            fflush($handle);
            @chmod($path, 0600);
            return $jwks;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function privateCacheDirectoryIsSafe(string $directory): bool
    {
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
                return false;
            }
        }
        clearstatcache(true, $directory);
        if (is_link($directory)) return false;
        $mode = fileperms($directory);
        return $mode !== false && ($mode & 0o077) === 0;
    }

    /** @return array<string, mixed> */
    private function downloadAppleJwks(): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException(503, 'Apple sign-in is unavailable on this server.');
        }
        $curl = curl_init(self::JWKS_URL);
        if ($curl === false) {
            throw new ApiException(503, 'Apple sign-in keys are temporarily unavailable.');
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
            CURLOPT_USERAGENT => 'PimPoPom-Apple-SignIn/1',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_JWKS_BYTES) {
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
            throw new ApiException(503, 'Apple sign-in keys are temporarily unavailable.');
        }
        try {
            $jwks = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(503, 'Apple sign-in keys are temporarily unavailable.');
        }
        if (!is_array($jwks) || array_is_list($jwks)) {
            throw new ApiException(503, 'Apple sign-in keys are temporarily unavailable.');
        }
        return $jwks;
    }
}
