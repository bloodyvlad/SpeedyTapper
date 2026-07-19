<?php

declare(strict_types=1);

namespace SpeedyTapper;

use OpenSSLAsymmetricKey;

final class AppStoreServerApiClient
{
    public function __construct(private readonly Config $config)
    {
        if (!$config->storeKitServerApiIsConfigured()) {
            throw new \InvalidArgumentException('App Store Server API configuration is incomplete.');
        }
    }

    public function getTransactionInfo(string $transactionId): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $transactionId) !== 1) {
            throw new \InvalidArgumentException('App Store transaction identifier is invalid.');
        }
        $response = $this->request(
            'GET',
            '/inApps/v1/transactions/' . rawurlencode($transactionId),
        );
        $signed = $response['signedTransactionInfo'] ?? null;
        if (!is_string($signed) || $signed === '') {
            throw new \RuntimeException('Apple returned no signed transaction information.');
        }
        return $signed;
    }

    /** @return array{signedPayloads: list<string>, paginationToken: ?string, hasMore: bool} */
    public function notificationHistory(
        int $startDateMs,
        int $endDateMs,
        ?string $paginationToken = null,
    ): array {
        if ($startDateMs < 1 || $endDateMs <= $startDateMs) {
            throw new \InvalidArgumentException('Notification history range is invalid.');
        }
        $body = ['startDate' => $startDateMs, 'endDate' => $endDateMs];
        $path = '/inApps/v1/notifications/history';
        if ($paginationToken !== null) {
            if ($paginationToken === '' || strlen($paginationToken) > 2048) {
                throw new \InvalidArgumentException('Notification history token is invalid.');
            }
            $path .= '?paginationToken=' . rawurlencode($paginationToken);
        }
        $response = $this->request('POST', $path, $body);
        $signedPayloads = [];
        foreach (($response['notificationHistory'] ?? []) as $item) {
            $signed = is_array($item) ? ($item['signedPayload'] ?? null) : null;
            if (is_string($signed) && $signed !== '') $signedPayloads[] = $signed;
        }
        $next = $response['paginationToken'] ?? null;
        return [
            'signedPayloads' => $signedPayloads,
            'paginationToken' => is_string($next) && $next !== '' ? $next : null,
            'hasMore' => ($response['hasMore'] ?? false) === true,
        ];
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $base = $this->config->storeKitEnvironment === 'Sandbox'
            ? 'https://api.storekit-sandbox.apple.com'
            : 'https://api.storekit.apple.com';
        $url = $base . $path;
        $encodedBody = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $handle = curl_init($url);
            if ($handle === false) throw new \RuntimeException('Could not initialize Apple API request.');
            $headers = [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->jwt(),
            ];
            if ($encodedBody !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($handle, CURLOPT_POSTFIELDS, $encodedBody);
            }
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $raw = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if (!is_string($raw)) {
                if ($attempt < 3) continue;
                throw new \RuntimeException('Apple API transport failed: ' . $error);
            }
            if ($status >= 200 && $status < 300) {
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || array_is_list($decoded)) {
                    throw new \RuntimeException('Apple API returned an invalid JSON object.');
                }
                return $decoded;
            }
            if (($status === 429 || $status >= 500) && $attempt < 3) continue;
            $errorBody = json_decode($raw, true);
            $code = is_array($errorBody) ? ($errorBody['errorCode'] ?? $status) : $status;
            throw new \RuntimeException('Apple API request failed with error ' . $code . '.');
        }
        throw new \RuntimeException('Apple API request failed.');
    }

    private function jwt(): string
    {
        $path = $this->config->storeKitPrivateKeyPath;
        $pem = is_string($path) ? @file_get_contents($path) : false;
        if (!is_string($pem) || $pem === '') {
            throw new \RuntimeException('The App Store Server API private key could not be read.');
        }
        $key = openssl_pkey_get_private($pem);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new \RuntimeException('The App Store Server API private key is invalid.');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1'
        ) {
            throw new \RuntimeException('The App Store Server API key must be P-256.');
        }
        $now = time();
        $header = $this->base64Url(json_encode([
            'alg' => 'ES256',
            'kid' => $this->config->storeKitKeyId,
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode([
            'iss' => $this->config->storeKitIssuerId,
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => 'appstoreconnect-v1',
            'bid' => $this->config->storeKitBundleId,
        ], JSON_THROW_ON_ERROR));
        $input = $header . '.' . $payload;
        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign the App Store Server API token.');
        }
        return $input . '.' . $this->base64Url($this->derSignatureToJose($der));
    }

    private function derSignatureToJose(string $der): string
    {
        $offset = 0;
        $readLength = static function () use ($der, &$offset): int {
            if ($offset >= strlen($der)) throw new \RuntimeException('Truncated DER signature.');
            $first = ord($der[$offset++]);
            if (($first & 0x80) === 0) return $first;
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
        if (($der[$offset++] ?? '') !== "\x30") throw new \RuntimeException('Invalid DER signature.');
        if ($readLength() !== strlen($der) - $offset) throw new \RuntimeException('Invalid DER signature.');
        $parts = [];
        for ($index = 0; $index < 2; $index++) {
            if (($der[$offset++] ?? '') !== "\x02") throw new \RuntimeException('Invalid DER signature.');
            $length = $readLength();
            $integer = ltrim(substr($der, $offset, $length), "\x00");
            $offset += $length;
            if (strlen($integer) > 32) throw new \RuntimeException('Invalid DER signature integer.');
            $parts[] = str_pad($integer, 32, "\x00", STR_PAD_LEFT);
        }
        if ($offset !== strlen($der)) throw new \RuntimeException('Invalid DER signature trailing data.');
        return $parts[0] . $parts[1];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
