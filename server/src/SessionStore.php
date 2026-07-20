<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class SessionStore
{
    private const AUTH_ID_KEY = 'speedytapper_session_auth_id';
    private const LEGACY_PLAYER_KEY = 'speedytapper_player_id';
    private const CSRF_KEY = 'speedytapper_csrf_token';
    private const RUN_BINDING_KEY = 'speedytapper_run_binding';
    private const PRIMARY_AUTHENTICATED_AT_KEY = 'speedytapper_primary_authenticated_at';
    private const PRIMARY_AUTHENTICATED_PROVIDER_KEY = 'speedytapper_primary_authenticated_provider';
    private const APPLE_CHALLENGE_KEY = 'speedytapper_apple_challenge';
    private const GAME_CENTER_CHALLENGE_KEY = 'speedytapper_game_center_challenge';
    private const AUTH_CHALLENGE_LIFETIME_SECONDS = 300;
    private const FINISH_RATE_KEY = 'speedytapper_finish_requests';
    private const FINISH_RATE_LIMIT = 20;
    private const FINISH_RATE_WINDOW_SECONDS = 60;
    private bool $started;

    public function __construct(
        private readonly bool $secure,
        private readonly SessionRegistry $registry,
    )
    {
        $this->started = session_status() === PHP_SESSION_ACTIVE;
        if ($this->started) return;

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) (30 * 24 * 60 * 60));
        session_name('speedytapper_session');
        session_set_cookie_params([
            'lifetime' => 30 * 24 * 60 * 60,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function playerId(): ?string
    {
        if (!$this->started && !isset($_COOKIE[session_name()])) {
            return null;
        }
        $this->start();
        $authId = $this->storedAuthId();
        if ($authId === null) {
            $this->clearAuthenticationState();
            return null;
        }
        $playerId = $this->registry->resolve($authId);
        if ($playerId === null) {
            $this->clearAuthenticationState();
        }
        return $playerId;
    }

    public function login(string $playerId, string $provider = PlayerIdentityService::PROVIDER_GOOGLE): void
    {
        $playerId = strtolower(trim($playerId));
        if (!Uuid::isValidV4($playerId)) {
            throw new \InvalidArgumentException('Session player ID must be a version 4 UUID.');
        }
        $provider = self::primaryProvider($provider);
        $this->start();
        $previousAuthId = $this->storedAuthId();
        $previousPlayerId = $previousAuthId === null
            ? null
            : $this->registry->resolve($previousAuthId);
        $finishRequests = $previousPlayerId === $playerId
            ? ($_SESSION[self::FINISH_RATE_KEY] ?? [])
            : [];
        if (!session_regenerate_id(true)) {
            throw new ApiException(503, 'The authenticated session could not be rotated.');
        }
        $authId = self::base64Url(random_bytes(32));
        try {
            $this->registry->rotate($previousAuthId, $authId, $playerId);
        } catch (\Throwable $error) {
            $this->clearAuthenticationState();
            throw $error;
        }
        $_SESSION = [
            self::AUTH_ID_KEY => $authId,
            self::CSRF_KEY => self::base64Url(random_bytes(32)),
            self::RUN_BINDING_KEY => self::base64Url(random_bytes(32)),
            self::PRIMARY_AUTHENTICATED_AT_KEY => time(),
            self::PRIMARY_AUTHENTICATED_PROVIDER_KEY => $provider,
            self::FINISH_RATE_KEY => is_array($finishRequests) ? $finishRequests : [],
        ];
    }

    public function markPrimaryAuthenticated(string $provider): void
    {
        $provider = self::primaryProvider($provider);
        $this->start();
        if ($this->storedAuthId() === null || $this->playerId() === null) {
            throw new ApiException(401, 'Sign in again to continue.');
        }
        $_SESSION[self::PRIMARY_AUTHENTICATED_AT_KEY] = time();
        $_SESSION[self::PRIMARY_AUTHENTICATED_PROVIDER_KEY] = $provider;
    }

    public function logout(): void
    {
        if (!$this->started && !isset($_COOKIE[session_name()])) {
            return;
        }
        $this->start();
        $authId = $this->storedAuthId();
        $registryError = null;
        try {
            if ($authId !== null) {
                $this->registry->revoke($authId);
            }
        } catch (\Throwable $error) {
            $registryError = $error;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42_000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        $this->started = false;
        if ($registryError !== null) {
            throw $registryError;
        }
    }

    public function csrfToken(): string
    {
        $this->start();
        $token = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($token) || strlen($token) !== 43) {
            $token = self::base64Url(random_bytes(32));
            $_SESSION[self::CSRF_KEY] = $token;
        }
        return $token;
    }

    public function requireCsrf(HttpRequest $request): void
    {
        $received = $request->header('X-SpeedyTapper-CSRF');
        $expected = $this->csrfToken();
        if (!is_string($received) || !hash_equals($expected, $received)) {
            throw new ApiException(403, 'The security token is missing or expired. Refresh and try again.');
        }
    }

    public function runBindingHash(): string
    {
        return hash('sha256', $this->runBinding(), true);
    }

    public function requireRunFinishCapacity(): void
    {
        $this->start();
        $now = time();
        $minimum = $now - self::FINISH_RATE_WINDOW_SECONDS;
        $stored = $_SESSION[self::FINISH_RATE_KEY] ?? [];
        $requests = [];
        if (is_array($stored)) {
            foreach ($stored as $timestamp) {
                if (is_int($timestamp) && $timestamp > $minimum && $timestamp <= $now) {
                    $requests[] = $timestamp;
                }
            }
        }
        if (count($requests) >= self::FINISH_RATE_LIMIT) {
            sort($requests, SORT_NUMERIC);
            $retryAfter = max(1, $requests[0] + self::FINISH_RATE_WINDOW_SECONDS - $now + 1);
            $_SESSION[self::FINISH_RATE_KEY] = $requests;
            throw new ApiException(429, 'Too many score submission attempts. Try again shortly.', [
                'Retry-After' => (string) $retryAfter,
            ]);
        }
        $requests[] = $now;
        $_SESSION[self::FINISH_RATE_KEY] = $requests;
    }

    public function requireRecentPrimaryAuthentication(int $maximumAgeSeconds = 900): void
    {
        if ($maximumAgeSeconds < 60 || $maximumAgeSeconds > 3600) {
            throw new \InvalidArgumentException('Recent-authentication window is invalid.');
        }
        $this->start();
        $authenticatedAt = $_SESSION[self::PRIMARY_AUTHENTICATED_AT_KEY] ?? null;
        $provider = $_SESSION[self::PRIMARY_AUTHENTICATED_PROVIDER_KEY] ?? null;
        $now = time();
        if (
            !is_int($authenticatedAt)
            || !is_string($provider)
            || !in_array(
                $provider,
                [PlayerIdentityService::PROVIDER_GOOGLE, PlayerIdentityService::PROVIDER_APPLE],
                true,
            )
            || $authenticatedAt > $now
            || $authenticatedAt < $now - $maximumAgeSeconds
        ) {
            throw new ApiException(
                403,
                'Sign in again before making this sensitive account change.',
            );
        }
    }

    /**
     * @return array{
     *     challengeId: string,
     *     nonce: string,
     *     state: string,
     *     intent: string,
     *     audience: string,
     *     expiresAt: string
     * }
     */
    public function issueAppleChallenge(string $intent, string $audience): array
    {
        if (!in_array($intent, ['login', 'register', 'link', 'reauth'], true)) {
            throw new ApiException(400, 'Apple sign-in intent is invalid.');
        }
        if ($audience === '' || strlen($audience) > 255) {
            throw new \InvalidArgumentException('Apple sign-in audience is invalid.');
        }
        $this->start();
        $challenge = [
            'challengeId' => self::base64Url(random_bytes(32)),
            'nonce' => self::base64Url(random_bytes(32)),
            'state' => self::base64Url(random_bytes(32)),
            'intent' => $intent,
            'audience' => $audience,
            'expiresAtUnix' => time() + self::AUTH_CHALLENGE_LIFETIME_SECONDS,
        ];
        $_SESSION[self::APPLE_CHALLENGE_KEY] = $challenge;
        return [
            'challengeId' => $challenge['challengeId'],
            'nonce' => $challenge['nonce'],
            'state' => $challenge['state'],
            'intent' => $challenge['intent'],
            'audience' => $challenge['audience'],
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $challenge['expiresAtUnix']),
        ];
    }

    /** @return array{nonce: string, intent: string, audience: string} */
    public function consumeAppleChallenge(mixed $challengeId, mixed $state): array
    {
        $this->start();
        $challenge = $_SESSION[self::APPLE_CHALLENGE_KEY] ?? null;
        unset($_SESSION[self::APPLE_CHALLENGE_KEY]);
        if (
            !is_array($challenge)
            || !is_string($challengeId)
            || !is_string($state)
            || !is_string($challenge['challengeId'] ?? null)
            || !is_string($challenge['state'] ?? null)
            || !is_string($challenge['nonce'] ?? null)
            || !is_string($challenge['intent'] ?? null)
            || !is_string($challenge['audience'] ?? null)
            || !is_int($challenge['expiresAtUnix'] ?? null)
            || $challenge['expiresAtUnix'] < time()
            || !hash_equals($challenge['challengeId'], $challengeId)
            || !hash_equals($challenge['state'], $state)
        ) {
            throw new ApiException(401, 'Apple sign-in challenge is invalid, expired, or already used.');
        }
        return [
            'nonce' => $challenge['nonce'],
            'intent' => $challenge['intent'],
            'audience' => $challenge['audience'],
        ];
    }

    /** @return array{challengeId: string, expiresAt: string} */
    public function issueGameCenterChallenge(): array
    {
        $this->start();
        $challenge = [
            'challengeId' => self::base64Url(random_bytes(32)),
            'issuedAtMilliseconds' => (int) floor(microtime(true) * 1_000),
            'expiresAtUnix' => time() + self::AUTH_CHALLENGE_LIFETIME_SECONDS,
        ];
        $_SESSION[self::GAME_CENTER_CHALLENGE_KEY] = $challenge;
        return [
            'challengeId' => $challenge['challengeId'],
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $challenge['expiresAtUnix']),
        ];
    }

    /** @return array{issuedAtMilliseconds: int} */
    public function consumeGameCenterChallenge(mixed $challengeId): array
    {
        $this->start();
        $challenge = $_SESSION[self::GAME_CENTER_CHALLENGE_KEY] ?? null;
        unset($_SESSION[self::GAME_CENTER_CHALLENGE_KEY]);
        if (
            !is_array($challenge)
            || !is_string($challengeId)
            || !is_string($challenge['challengeId'] ?? null)
            || !is_int($challenge['issuedAtMilliseconds'] ?? null)
            || !is_int($challenge['expiresAtUnix'] ?? null)
            || $challenge['expiresAtUnix'] < time()
            || !hash_equals($challenge['challengeId'], $challengeId)
        ) {
            throw new ApiException(401, 'Game Center link challenge is invalid, expired, or already used.');
        }
        return ['issuedAtMilliseconds' => $challenge['issuedAtMilliseconds']];
    }

    public function close(): void
    {
        if (!$this->started) {
            return;
        }
        if (!session_write_close()) {
            throw new ApiException(503, 'Session storage could not be released.');
        }
        $this->started = false;
    }

    private function runBinding(): string
    {
        $this->start();
        $binding = $_SESSION[self::RUN_BINDING_KEY] ?? null;
        if (!is_string($binding) || strlen($binding) !== 43) {
            $binding = self::base64Url(random_bytes(32));
            $_SESSION[self::RUN_BINDING_KEY] = $binding;
        }
        return $binding;
    }

    private function storedAuthId(): ?string
    {
        $value = $_SESSION[self::AUTH_ID_KEY] ?? null;
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1
            ? $value
            : null;
    }

    private function clearAuthenticationState(): void
    {
        unset(
            $_SESSION[self::AUTH_ID_KEY],
            $_SESSION[self::LEGACY_PLAYER_KEY],
            $_SESSION[self::RUN_BINDING_KEY],
            $_SESSION[self::PRIMARY_AUTHENTICATED_AT_KEY],
            $_SESSION[self::PRIMARY_AUTHENTICATED_PROVIDER_KEY],
            $_SESSION[self::APPLE_CHALLENGE_KEY],
            $_SESSION[self::GAME_CENTER_CHALLENGE_KEY],
            $_SESSION[self::FINISH_RATE_KEY],
        );
    }

    private static function primaryProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array(
            $provider,
            [PlayerIdentityService::PROVIDER_GOOGLE, PlayerIdentityService::PROVIDER_APPLE],
            true,
        )) {
            throw new \InvalidArgumentException('Session authentication provider is invalid.');
        }
        return $provider;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function start(): void
    {
        if ($this->started) return;
        if (!session_start()) {
            throw new ApiException(503, 'Session storage is temporarily unavailable.');
        }
        $this->started = true;
    }
}
