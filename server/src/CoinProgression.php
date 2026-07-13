<?php

declare(strict_types=1);

namespace SpeedyTapper;

final readonly class CoinProgression
{
    public const MILLIS_PER_COIN = 60_000;

    public function __construct(
        public int $coinsEarned,
        public int $remainderMs,
    ) {
    }

    public static function accrue(int $remainderMs, int $durationMs): self
    {
        if ($remainderMs < 0 || $remainderMs >= self::MILLIS_PER_COIN) {
            throw new \InvalidArgumentException('Coin time remainder is invalid.');
        }
        if ($durationMs < 0) {
            throw new \InvalidArgumentException('Run duration is invalid.');
        }

        $eligibleMs = $remainderMs + $durationMs;
        return new self(
            coinsEarned: intdiv($eligibleMs, self::MILLIS_PER_COIN),
            remainderMs: $eligibleMs % self::MILLIS_PER_COIN,
        );
    }
}
