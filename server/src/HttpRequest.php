<?php

declare(strict_types=1);

namespace SpeedyTapper;

final readonly class HttpRequest
{
    private const MAX_BODY_BYTES = 16_384;

    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $server,
        private string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/api');
        $path = parse_url($uri, PHP_URL_PATH);
        $rawBody = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($rawBody === false) {
            $rawBody = '';
        }
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new ApiException(413, 'Request data is too large.');
        }

        return new self(
            method: $method,
            path: is_string($path) ? rtrim($path, '/') ?: '/' : '/',
            query: $_GET,
            server: $_SERVER,
            rawBody: $rawBody,
        );
    }

    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }

        try {
            $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'Request data must be valid JSON.');
        }
        if (!is_array($decoded) || !str_starts_with(ltrim($this->rawBody), '{')) {
            throw new ApiException(400, 'Request data is invalid.');
        }

        return $decoded;
    }

    public function isSecure(): bool
    {
        $forwarded = strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $forwarded === 'https' || (($this->server['HTTPS'] ?? '') !== '' && ($this->server['HTTPS'] ?? '') !== 'off');
    }

    public function guardSameOriginMutation(): void
    {
        $fetchSite = strtolower((string) ($this->server['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($fetchSite === 'cross-site') {
            throw new ApiException(403, 'Cross-site requests are not allowed.');
        }

        $origin = $this->server['HTTP_ORIGIN'] ?? null;
        if (!is_string($origin) || $origin === '') {
            return;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originScheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        $requestHost = strtolower(explode(':', (string) ($this->server['HTTP_HOST'] ?? ''))[0]);
        $expectedScheme = $this->isSecure() ? 'https' : 'http';
        if (
            !is_string($originHost)
            || strtolower($originHost) !== $requestHost
            || $originScheme !== $expectedScheme
        ) {
            throw new ApiException(403, 'Cross-site requests are not allowed.');
        }
    }
}
