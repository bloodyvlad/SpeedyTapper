<?php

declare(strict_types=1);

namespace SpeedyTapper;

use Closure;
use OpenSSLAsymmetricKey;

final class AppStoreConnectGameCenterClient implements GameCenterSubmissionClient
{
    private const BASE_URL = 'https://api.appstoreconnect.apple.com';

    private ?string $cachedJwt = null;
    private int $cachedJwtExpiresAt = 0;

    /**
     * The optional transport is test-only. It receives URL, headers, and the
     * JSON body and returns status, response headers, and response body.
     *
     * @param null|Closure(string, list<string>, string): array{
     *   status: int,
     *   headers?: array<string, string>,
     *   body: string
     * } $transport
     */
    public function __construct(
        private readonly Config $config,
        private readonly ?Closure $transport = null,
    ) {
        if (!$config->gameCenterPublicationIsConfigured()) {
            throw new \InvalidArgumentException(
                'App Store Connect Game Center configuration is incomplete.',
            );
        }
    }

    public function submitLeaderboard(
        string $scopedPlayerId,
        string $vendorIdentifier,
        int $score,
        bool $preReleased,
    ): string {
        if (!GameCenterCatalog::supportsLeaderboardVendorIdentifier($vendorIdentifier)) {
            throw new \InvalidArgumentException(
                'Unknown Game Center leaderboard identifier.',
            );
        }
        if ($score < 0) {
            throw new \InvalidArgumentException('Game Center score cannot be negative.');
        }
        return $this->submit(
            '/v1/gameCenterLeaderboardEntrySubmissions',
            'gameCenterLeaderboardEntrySubmissions',
            [
                'bundleId' => $this->config->storeKitBundleId,
                'vendorIdentifier' => $vendorIdentifier,
                'scopedPlayerId' => self::normalizedScopedPlayerId($scopedPlayerId),
                // Apple's submission example and live endpoint require a
                // decimal JSON string despite conflicting schema metadata.
                'score' => (string) $score,
                'preReleased' => $preReleased,
            ],
        );
    }

    public function submitAchievement(
        string $scopedPlayerId,
        string $achievementId,
        bool $preReleased,
    ): string {
        return $this->submit(
            '/v1/gameCenterPlayerAchievementSubmissions',
            'gameCenterPlayerAchievementSubmissions',
            [
                'bundleId' => $this->config->storeKitBundleId,
                'vendorIdentifier' =>
                    GameCenterCatalog::achievementVendorIdentifier($achievementId),
                'scopedPlayerId' => self::normalizedScopedPlayerId($scopedPlayerId),
                'percentageAchieved' => 100,
                'preReleased' => $preReleased,
            ],
        );
    }

    /** @param array<string, bool|int|string> $attributes */
    private function submit(string $path, string $type, array $attributes): string
    {
        $url = self::BASE_URL . $path;
        $body = json_encode([
            'data' => [
                'type' => $type,
                'attributes' => $attributes,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->jwt(),
            'Content-Type: application/json',
        ];

        if ($this->transport !== null) {
            $response = ($this->transport)($url, $headers, $body);
        } else {
            $response = $this->curl($url, $headers, $body);
        }
        $status = (int) ($response['status'] ?? 0);
        $raw = $response['body'] ?? '';
        if ($status !== 201) {
            throw $this->responseException(
                $status,
                is_string($raw) ? $raw : '',
                $attributes,
                $body,
            );
        }
        try {
            $decoded = json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new GameCenterAppleApiException(
                'Apple Game Center returned an invalid success response.',
                true,
                $status,
                previous: $error,
            );
        }
        $submissionId = is_array($decoded)
            ? ($decoded['data']['id'] ?? null)
            : null;
        if (!is_string($submissionId) || $submissionId === '' || strlen($submissionId) > 255) {
            throw new GameCenterAppleApiException(
                'Apple Game Center returned no submission identifier.',
                true,
                $status,
            );
        }
        return $submissionId;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function curl(string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new GameCenterAppleApiException(
                'The Game Center HTTP client is unavailable.',
                false,
            );
        }
        $responseHeaders = [];
        $handle = curl_init($url);
        if ($handle === false) {
            throw new GameCenterAppleApiException(
                'Could not initialize the Game Center request.',
                true,
            );
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function (
                mixed $unused,
                string $line,
            ) use (&$responseHeaders): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $value = trim(substr($line, $separator + 1));
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw)) {
            throw new GameCenterAppleApiException(
                'Apple Game Center transport failed'
                    . ($error === '' ? '.' : ': ' . $error),
                true,
                $status > 0 ? $status : null,
            );
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw];
    }

    /**
     * @param array<string, bool|int|string> $attributes
     */
    private function responseException(
        int $status,
        string $raw,
        array $attributes,
        string $requestBody,
    ): GameCenterAppleApiException {
        $code = null;
        $title = null;
        $detail = null;
        $sourcePointer = null;
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $first = is_array($decoded) && is_array($decoded['errors'] ?? null)
                ? ($decoded['errors'][0] ?? null)
                : null;
            if (is_array($first)) {
                $candidateCode = $first['code'] ?? null;
                $code = self::sanitizedAppleCode($candidateCode);
                $sensitiveValues = [$requestBody];
                $scopedPlayerId = $attributes['scopedPlayerId'] ?? null;
                if (is_string($scopedPlayerId) && $scopedPlayerId !== '') {
                    $sensitiveValues[] = $scopedPlayerId;
                }
                $title = self::sanitizedAppleText(
                    $first['title'] ?? null,
                    $sensitiveValues,
                    120,
                );
                $detail = self::sanitizedAppleText(
                    $first['detail'] ?? null,
                    $sensitiveValues,
                    280,
                );
                $source = $first['source'] ?? null;
                $sourcePointer = self::sanitizedAppleSourcePointer(
                    is_array($source) ? ($source['pointer'] ?? null) : null,
                );
            }
        } catch (\JsonException) {
        }
        $retryable = $status === 0
            || $status === 408
            || $status === 425
            || $status === 429
            || $status >= 500;
        return new GameCenterAppleApiException(
            'Apple Game Center submission failed.',
            $retryable,
            $status > 0 ? $status : null,
            $code,
            $title,
            $detail,
            $sourcePointer,
        );
    }

    private static function sanitizedAppleCode(mixed $value): ?string
    {
        if (
            !is_string($value)
            || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $value) !== 1
        ) {
            return null;
        }
        return $value;
    }

    /**
     * Apple diagnostic strings are untrusted and may reflect request values.
     * Keep only bounded operator context after removing exact request secrets
     * and common credential/player-identity shapes.
     *
     * @param list<string> $sensitiveValues
     */
    private static function sanitizedAppleText(
        mixed $value,
        array $sensitiveValues,
        int $limit,
    ): ?string {
        if (!is_string($value) || $value === '' || $limit < 1) {
            return null;
        }
        foreach ($sensitiveValues as $sensitiveValue) {
            if ($sensitiveValue !== '') {
                $value = str_replace($sensitiveValue, '[redacted]', $value);
            }
        }
        $patterns = [
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/is'
                => '[redacted-key]',
            '/\bAuthorization\s*:\s*Bearer\s+\S+/i' => 'Authorization: Bearer [redacted]',
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{12,}/i' => 'Bearer [redacted]',
            '/\beyJ[A-Za-z0-9_-]{5,}(?:\.[A-Za-z0-9_-]{5,}){2}\b/'
                => '[redacted-token]',
            '/\b(?:G|T|U):[A-Za-z0-9._~+\/=-]{3,}\b/' => '[redacted-player]',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i'
                => '[redacted-id]',
            '/\b[A-Za-z0-9+\/_-]{40,}={0,2}\b/' => '[redacted-opaque]',
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => '[redacted-email]',
        ];
        $value = preg_replace(
            array_keys($patterns),
            array_values($patterns),
            $value,
        );
        if (!is_string($value)) {
            return null;
        }
        // Reject reflected JSON fragments even when Apple did not echo the
        // complete request body byte-for-byte.
        if (str_contains($value, '{') || str_contains($value, '}')) {
            return null;
        }
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = is_string($value) ? preg_replace('/\s+/u', ' ', $value) : null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return mb_strcut($value, 0, $limit, 'UTF-8');
    }

    private static function sanitizedAppleSourcePointer(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return in_array($value, [
            '/data',
            '/data/type',
            '/data/attributes/bundleId',
            '/data/attributes/vendorIdentifier',
            '/data/attributes/scopedPlayerId',
            '/data/attributes/score',
            '/data/attributes/percentageAchieved',
            '/data/attributes/preReleased',
        ], true) ? $value : null;
    }

    private function jwt(): string
    {
        $now = time();
        if ($this->cachedJwt !== null && $this->cachedJwtExpiresAt - 60 > $now) {
            return $this->cachedJwt;
        }
        $path = $this->config->gameCenterApiPrivateKeyPath;
        $pem = is_string($path) ? @file_get_contents($path) : false;
        if (!is_string($pem) || $pem === '') {
            throw new \RuntimeException('The Game Center App Store Connect key could not be read.');
        }
        $key = openssl_pkey_get_private($pem);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new \RuntimeException('The Game Center App Store Connect key is invalid.');
        }
        $details = openssl_pkey_get_details($key);
        if (
            !is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1'
        ) {
            throw new \RuntimeException('The Game Center App Store Connect key must be P-256.');
        }
        $expiresAt = $now + 600;
        $header = self::base64Url(json_encode([
            'alg' => 'ES256',
            'kid' => $this->config->gameCenterApiKeyId,
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));
        $payload = self::base64Url(json_encode([
            'iss' => $this->config->gameCenterApiIssuerId,
            'iat' => $now,
            'exp' => $expiresAt,
            'aud' => 'appstoreconnect-v1',
        ], JSON_THROW_ON_ERROR));
        $input = $header . '.' . $payload;
        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign the Game Center API token.');
        }
        $this->cachedJwt = $input . '.' . self::base64Url(self::derSignatureToJose($der));
        $this->cachedJwtExpiresAt = $expiresAt;
        return $this->cachedJwt;
    }

    private static function normalizedScopedPlayerId(string $value): string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new \InvalidArgumentException('Game Center scoped player identifier is invalid.');
        }
        return $value;
    }

    private static function derSignatureToJose(string $der): string
    {
        $offset = 0;
        $readLength = static function () use ($der, &$offset): int {
            if ($offset >= strlen($der)) {
                throw new \RuntimeException('Truncated DER signature.');
            }
            $first = ord($der[$offset++]);
            if (($first & 0x80) === 0) {
                return $first;
            }
            $count = $first & 0x7f;
            if ($count < 1 || $count > 2 || $offset + $count > strlen($der)) {
                throw new \RuntimeException('Invalid DER signature length.');
            }
            $length = 0;
            for ($index = 0; $index < $count; $index++) {
                $length = ($length << 8) | ord($der[$offset++]);
            }
            return $length;
        };
        if (($der[$offset++] ?? '') !== "\x30") {
            throw new \RuntimeException('Invalid DER signature.');
        }
        if ($readLength() !== strlen($der) - $offset) {
            throw new \RuntimeException('Invalid DER signature.');
        }
        $parts = [];
        for ($index = 0; $index < 2; $index++) {
            if (($der[$offset++] ?? '') !== "\x02") {
                throw new \RuntimeException('Invalid DER signature.');
            }
            $length = $readLength();
            $integer = ltrim(substr($der, $offset, $length), "\x00");
            $offset += $length;
            if (strlen($integer) > 32) {
                throw new \RuntimeException('Invalid DER signature integer.');
            }
            $parts[] = str_pad($integer, 32, "\x00", STR_PAD_LEFT);
        }
        if ($offset !== strlen($der)) {
            throw new \RuntimeException('Invalid DER signature trailing data.');
        }
        return $parts[0] . $parts[1];
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
