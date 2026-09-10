<?php

declare(strict_types=1);

namespace SpeedyTapper;

/** Exact v4-only pickup/tempo state. Times remain unscaled wall milliseconds. */
final class ArcadePowerUpReplay
{
    public const HEART = 0;
    public const CLOCK = 1;
    private const MINIMUM_DELAY_MS = 12_000;
    private const MAXIMUM_DELAY_MS = 20_000;
    private const RETRY_MS = 250;
    private const LIFETIME_MS = 3_000;
    private const MAX_TRANSITION_LAG_MS = 5_000;

    private ?array $active = null;
    private int $nextId = 1;
    private int $minimumAt = self::MINIMUM_DELAY_MS;
    private int $maximumAt = self::MAXIMUM_DELAY_MS;
    private ?int $clockHandledAt = null;
    private int $restoredLives = 0;

    public function occupiedCell(): ?int
    {
        return $this->active['cell'] ?? null;
    }

    public function restoredLives(): int
    {
        return $this->restoredLives;
    }

    /** Pickup classification precedes wrong/empty/late contact classification. */
    public function assertMissContact(int $inputAt, int $cell, int $eventIndex): void
    {
        if ($this->active !== null && $cell === $this->active['cell']
            && $inputAt >= $this->active['appearedAt']) {
            // An expired-but-uncommitted pickup is ignored by Swift, not a miss.
            // Timer timeouts use -1; a queued preappearance contact stays a miss.
            $this->invalid('Power-up contact cannot be encoded as a miss.', $eventIndex);
        }
    }

    public function scaleInterval(int $baseMs, int $sampledAt): int
    {
        if ($this->clockHandledAt === null) {
            return $baseMs;
        }
        $elapsed = min(10_000, max(0, $sampledAt - $this->clockHandledAt));
        $rateUnits = 70_000 + 3 * $elapsed;
        return intdiv($baseMs * 100_000 + $rateUnits - 1, $rateUnits);
    }

    public function assertBeforeEvent(int $type, int $logicalAt, int $processedAt, int $eventIndex): void
    {
        if (!in_array($type, [RunProof::EVENT_POWER_UP_ACTIVATE, RunProof::EVENT_POWER_UP_TICK], true)
            && $processedAt > $this->maximumAt + self::MAX_TRANSITION_LAG_MS) {
            $this->invalid('The next power-up opportunity is missing.', $eventIndex);
        }
        // The renderer hides at the deadline, then drains queued original-contact
        // inputs before committing event 9. Keep the cell reserved in that window.
        if ($this->active !== null && $logicalAt > $this->active['expiresAt'] + self::MAX_TRANSITION_LAG_MS
            && $type !== RunProof::EVENT_POWER_UP_EXPIRE) {
            $this->invalid('An expired power-up transition is missing.', $eventIndex);
        }
    }

    public function activate(
        array $event,
        int $dimension,
        ?int $targetCell,
        array $activeDecoys,
        int $recoveryUntil,
        int $eventIndex,
    ): void {
        [, $at, $id, $kind, $cell, $lifetime] = $event;
        $this->assertOpportunity($at, $recoveryUntil, $eventIndex);
        $available = $this->availableCells($dimension, $targetCell, $activeDecoys);
        if ($this->active !== null || $dimension < 2 || count($available) < 2
            || !in_array($cell, $available, true) || $id !== $this->nextId
            || !in_array($kind, [self::HEART, self::CLOCK], true) || $lifetime !== self::LIFETIME_MS) {
            $this->invalid('Power-up placement, identity or lifetime is invalid.', $eventIndex);
        }
        $this->active = ['id' => $id, 'kind' => $kind, 'cell' => $cell,
            'appearedAt' => $at, 'expiresAt' => $at + self::LIFETIME_MS];
        $this->nextId++;
        $this->scheduleAfter($at);
    }

    public function ignoredOpportunity(
        int $at,
        int $dimension,
        ?int $targetCell,
        array $activeDecoys,
        int $recoveryUntil,
        int $eventIndex,
    ): void {
        $this->assertOpportunity($at, $recoveryUntil, $eventIndex);
        if ($this->active === null && $dimension >= 2
            && count($this->availableCells($dimension, $targetCell, $activeDecoys)) >= 2) {
            $this->invalid('An ignored power-up opportunity could have placed a pickup.', $eventIndex);
        }
        $this->minimumAt = $at + self::RETRY_MS;
        $this->maximumAt = $this->minimumAt;
    }

    /** Returns derived lives; a full-heart pickup is consumed without benefit. */
    public function claim(array $event, int $lives, int $recoveryUntil, int $eventIndex): int
    {
        [, $inputAt, $handledAt, $id, $cell] = $event;
        if ($this->active === null || $inputAt < $this->active['appearedAt']
            || $inputAt >= $this->active['expiresAt'] || $id !== $this->active['id']
            || $cell !== $this->active['cell'] || $inputAt + 1 < $recoveryUntil || $lives <= 0) {
            $this->invalid('Power-up pickup does not match a live collectible.', $eventIndex);
        }
        if ($this->active['kind'] === self::HEART && $lives < 3) {
            $lives++;
            $this->restoredLives++;
        } elseif ($this->active['kind'] === self::CLOCK) {
            $this->clockHandledAt = $handledAt;
        }
        $this->active = null;
        return $lives;
    }

    public function expire(array $event, int $eventIndex): void
    {
        [, $at, $id] = $event;
        if ($this->active === null || $id !== $this->active['id']
            || $at < $this->active['expiresAt']
            || $at > $this->active['expiresAt'] + self::MAX_TRANSITION_LAG_MS) {
            $this->invalid('Power-up expiry does not match a live collectible.', $eventIndex);
        }
        $this->active = null;
    }

    public function loseLife(int $handledAt, int $recoveryMs): void
    {
        $this->active = null;
        $this->scheduleAfter($handledAt + $recoveryMs);
    }

    private function scheduleAfter(int $baseAt): void
    {
        $this->minimumAt = $baseAt + self::MINIMUM_DELAY_MS;
        $this->maximumAt = $baseAt + self::MAXIMUM_DELAY_MS;
    }

    private function assertOpportunity(int $at, int $recoveryUntil, int $eventIndex): void
    {
        if ($at + 1 < $this->minimumAt || $at > $this->maximumAt + self::MAX_TRANSITION_LAG_MS
            || $at + 1 < $recoveryUntil) {
            $this->invalid('Power-up opportunity is outside its scheduling window.', $eventIndex);
        }
    }

    private function availableCells(int $dimension, ?int $targetCell, array $activeDecoys): array
    {
        $available = [];
        for ($cell = 0; $cell < $dimension ** 2; $cell++) {
            if ($cell !== $targetCell && !isset($activeDecoys[$cell])) {
                $available[] = $cell;
            }
        }
        return $available;
    }

    private function invalid(string $message, int $eventIndex): never
    {
        throw new ApiException(400, $message . ' Event ' . $eventIndex . '.');
    }
}
