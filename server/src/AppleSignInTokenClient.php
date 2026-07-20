<?php

declare(strict_types=1);

namespace SpeedyTapper;

use Closure;
use Firebase\JWT\JWT;

/** Exchanges one-time Apple authorization codes and revokes retained tokens. */
final class AppleSignInTokenClient implements AppleAuthorizationCodeClient
{
    private const TOKEN_URL = 'https://appleid.apple.com/auth/token';
    private const REVOKE_URL = 'https://appleid.apple.com/auth/revoke';
    private const MAX_RESPONSE_BYTES = 65_536;

    /** @var Closure(string, array<string, string>): array{status: int, body: string} */
    private Closure $transport;
    /** @var Closure(): int */
    private Closure $clock;
    private string $privateKeyPem;

    /**
     * @param null|callable(string, array<string, string>): array{status: int, body: string} $transport
     * @param null|callable(): int $clock
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $teamId,
        private readonly string $keyId,
        string $privateKeyPath,
        ?callable $transport = null,
        ?callable $clock = null,
    ) {
        if (
            preg_match('/^[A-Za-z0-9.-]{1,255}$/D', $this->clientId) !== 1
            || preg_match('/^[A-Z0-9]{10}$/D', $this->teamId) !== 1
            || preg_match('/^[A-Z0-9]{10}$/D', $this->keyId) !== 1
            || !is_readable($privateKeyPath)
        ) {
            throw new \InvalidArgumentException('Apple Sign in token configuration is invalid.');
        }
        $privateKeyPem = file_get_contents($privateKeyPath);
        $privateKey = is_string($privateKeyPem)
            ? openssl_pkey_get_private($privateKeyPem)
            : false;
        $details = $privateKey === false ? false : openssl_pkey_get_details($privateKey);
        if (
            !is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || (int) ($details['bits'] ?? 0) !== 256
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1'
        ) {
            throw new \InvalidArgumentException('Apple Sign in private key must be a P-256 key.');
        }
        $this->privateKeyPem = $privateKeyPem;
        $this->transport = $transport === null
            ? Closure::fromCallable([$this, 'postForm'])
            : Closure::fromCallable($transport);
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public function exchange(string $authorizationCode): AppleTokenExchange
    {
        $authorizationCode = $this->validatedOpaqueToken($authorizationCode, 'authorization code');
        $response = ($this->transport)(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $authorizationCode,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret(),
        ]);
        $payload = $this->decodedResponse($response, 'exchange');
        $identityToken = $payload['id_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;
        if (!is_string($identityToken) || ($refreshToken !== null && !is_string($refreshToken))) {
            throw new ApiException(503, 'Apple sign-in returned an incomplete token response.');
        }
        return new AppleTokenExchange($identityToken, $refreshToken);
    }

    public function revoke(string $refreshToken): void
    {
        $refreshToken = $this->validatedOpaqueToken($refreshToken, 'refresh token');
        $response = ($this->transport)(self::REVOKE_URL, [
            'token' => $refreshToken,
            'token_type_hint' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret(),
        ]);
        if (($response['status'] ?? 0) !== 200) {
            throw new ApiException(503, 'Apple authorization could not be revoked. Try account deletion again.');
        }
    }

    private function clientSecret(): string
    {
        $now = ($this->clock)();
        return JWT::encode([
            'iss' => $this->teamId,
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => 'https://appleid.apple.com',
            'sub' => $this->clientId,
        ], $this->privateKeyPem, 'ES256', $this->keyId);
    }

    private function validatedOpaqueToken(string $value, string $label): string
    {
        if (
            $value === ''
            || strlen($value) > 4_096
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
        ) {
            throw new ApiException(400, 'Apple ' . $label . ' is invalid.');
        }
        return $value;
    }

    /** @param array{status: int, body: string} $response @return array<string, mixed> */
    private function decodedResponse(array $response, string $operation): array
    {
        $status = $response['status'] ?? 0;
        $body = $response['body'] ?? '';
        try {
            $payload = is_string($body) && $body !== ''
                ? json_decode($body, true, 16, JSON_THROW_ON_ERROR)
                : null;
        } catch (\JsonException) {
            $payload = null;
        }
        if ($status !== 200 || !is_array($payload) || array_is_list($payload)) {
            $error = is_array($payload) ? ($payload['error'] ?? null) : null;
            if ($status === 400 && $error === 'invalid_grant') {
                throw new ApiException(401, 'Apple authorization code is invalid or already used.');
            }
            throw new ApiException(503, 'Apple sign-in token ' . $operation . ' is temporarily unavailable.');
        }
        return $payload;
    }

    /** @param array<string, string> $fields @return array{status: int, body: string} */
    private function postForm(string $url, array $fields): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException(503, 'Apple sign-in token service is unavailable.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new ApiException(503, 'Apple sign-in token service is unavailable.');
        }
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PimPoPom-Apple-SignIn/1',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) return 0;
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
        if ($success !== true) {
            throw new ApiException(503, 'Apple sign-in token service is temporarily unavailable.');
        }
        return ['status' => $status, 'body' => $body];
    }
}
