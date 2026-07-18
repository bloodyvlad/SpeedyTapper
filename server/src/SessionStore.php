<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class SessionStore
{
    private const PLAYER_KEY = 'speedytapper_player_id';
    private const CSRF_KEY = 'speedytapper_csrf_token';
    private const RUN_BINDING_KEY = 'speedytapper_run_binding';
    private const GOOGLE_AUTHENTICATED_AT_KEY = 'speedytapper_google_authenticated_at';
    private const FINISH_RATE_KEY = 'speedytapper_finish_requests';
    private const FINISH_RATE_LIMIT = 20;
    private const FINISH_RATE_WINDOW_SECONDS = 60;
    private bool $started;

    public function __construct(private readonly bool $secure)
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
        $value = $_SESSION[self::PLAYER_KEY] ?? null;
        return is_string($value) && preg_match('/^[0-9a-f-]{36}$/', $value) ? $value : null;
    }

    public function login(string $playerId): void
    {
        $this->start();
        $finishRequests = ($_SESSION[self::PLAYER_KEY] ?? null) === $playerId
            ? ($_SESSION[self::FINISH_RATE_KEY] ?? [])
            : [];
        session_regenerate_id(true);
        $_SESSION = [
            self::PLAYER_KEY => $playerId,
            self::CSRF_KEY => self::base64Url(random_bytes(32)),
            self::RUN_BINDING_KEY => self::base64Url(random_bytes(32)),
            self::GOOGLE_AUTHENTICATED_AT_KEY => time(),
            self::FINISH_RATE_KEY => is_array($finishRequests) ? $finishRequests : [],
        ];
    }

    public function logout(): void
    {
        if (!$this->started && !isset($_COOKIE[session_name()])) {
            return;
        }
        $this->start();
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

    public function requireRecentGoogleAuthentication(int $maximumAgeSeconds = 900): void
    {
        if ($maximumAgeSeconds < 60 || $maximumAgeSeconds > 3600) {
            throw new \InvalidArgumentException('Recent-authentication window is invalid.');
        }
        $this->start();
        $authenticatedAt = $_SESSION[self::GOOGLE_AUTHENTICATED_AT_KEY] ?? null;
        $now = time();
        if (
            !is_int($authenticatedAt)
            || $authenticatedAt > $now
            || $authenticatedAt < $now - $maximumAgeSeconds
        ) {
            throw new ApiException(
                403,
                'Sign in with Google again before changing leaderboard records.',
            );
        }
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
