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
        int $score,
        bool $preReleased,
    ): string {
        if ($score < 0) {
            throw new \InvalidArgumentException('Game Center score cannot be negative.');
        }
        return $this->submit(
            '/v1/gameCenterLeaderboardEntrySubmissions',
            'gameCenterLeaderboardEntrySubmissions',
            [
                'bundleId' => $this->config->storeKitBundleId,
                'vendorIdentifier' => GameCenterCatalog::LEADERBOARD_ARCADE_VERIFIED,
                'scopedPlayerId' => self::normalizedScopedPlayerId($scopedPlayerId),
                // Apple's current App Store Connect schema requires a JSON
                // number. PHP integers are 64-bit on the supported runtime.
                'score' => $score,
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
            throw $this->responseException($status, is_string($raw) ? $raw : '');
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

    private function responseException(int $status, string $raw): GameCenterAppleApiException
    {
        $code = null;
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $first = is_array($decoded) && is_array($decoded['errors'] ?? null)
                ? ($decoded['errors'][0] ?? null)
                : null;
            if (is_array($first)) {
                $candidateCode = $first['code'] ?? null;
                $code = is_string($candidateCode) ? mb_strcut($candidateCode, 0, 128) : null;
            }
        } catch (\JsonException) {
        }
        $retryable = $status === 0
            || $status === 408
            || $status === 425
            || $status === 429
            || $status >= 500;
        return new GameCenterAppleApiException(
            'Apple Game Center submission failed'
                . ($code === null ? '' : ' (' . $code . ')')
                . '.',
            $retryable,
            $status > 0 ? $status : null,
            $code,
        );
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
