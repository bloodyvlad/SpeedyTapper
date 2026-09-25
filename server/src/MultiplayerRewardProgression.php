<?php

declare(strict_types=1);

namespace SpeedyTapper;

/** Independent multiplayer lane: two earned coins per complete alive minute. */
final readonly class MultiplayerRewardProgression
{
    public const MINUTE_MS = 60_000;
    public const COINS_PER_MINUTE = 2;

    public function __construct(public int $coinsEarned, public int $remainderMs, public int $totalAliveMs)
    {
    }

    public static function accrue(int $totalAliveMs, int $eligibleAliveMs): self
    {
        if ($totalAliveMs < 0 || $eligibleAliveMs < 0 || $eligibleAliveMs > 900_000
            || $totalAliveMs > PHP_INT_MAX - $eligibleAliveMs) {
            throw new \InvalidArgumentException('Multiplayer reward time is invalid.');
        }
        $after = $totalAliveMs + $eligibleAliveMs;
        return new self(
            self::COINS_PER_MINUTE * (intdiv($after, self::MINUTE_MS) - intdiv($totalAliveMs, self::MINUTE_MS)),
            $after % self::MINUTE_MS,
            $after,
        );
    }
}
