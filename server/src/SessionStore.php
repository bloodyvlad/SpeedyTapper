<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class SessionStore
{
    private const PLAYER_KEY = 'speedytapper_player_id';
    private const SUBMISSION_KEY = 'speedytapper_score_submissions';
    private const SUBMISSION_WINDOW_SECONDS = 60;
    private const SUBMISSION_LIMIT = 10;
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
        session_regenerate_id(true);
        $_SESSION = [self::PLAYER_KEY => $playerId];
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

    public function enforceScoreRateLimit(): void
    {
        $this->start();
        $now = time();
        $windowStart = $now - self::SUBMISSION_WINDOW_SECONDS;
        $timestamps = array_values(array_filter(
            is_array($_SESSION[self::SUBMISSION_KEY] ?? null) ? $_SESSION[self::SUBMISSION_KEY] : [],
            static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $windowStart,
        ));
        if (count($timestamps) >= self::SUBMISSION_LIMIT) {
            $retryAfter = max(1, $timestamps[0] + self::SUBMISSION_WINDOW_SECONDS - $now);
            throw new ApiException(429, 'Too many score submissions. Try again shortly.', [
                'Retry-After' => (string) $retryAfter,
            ]);
        }
        $timestamps[] = $now;
        $_SESSION[self::SUBMISSION_KEY] = $timestamps;
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
