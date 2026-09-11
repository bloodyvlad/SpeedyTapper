<?php

declare(strict_types=1);

namespace SpeedyTapper;

/**
 * A compact, versioned history of the browser-visible gameplay transitions.
 *
 * The proof is deliberately data-only. RunProofValidator is the sole place that
 * interprets the event tuples and derives a score from them.
 */
final readonly class RunProof
{
    public const BUILD_ID = '20260729-2';
    public const RULESET = 'reaction-proof-v3';
    public const PROOF_VERSION = 2;
    public const POWER_UP_RULESET = 'reaction-proof-v4';
    public const POWER_UP_PROOF_VERSION = 3;
    public const FOUR_BY_FOUR_POWER_UP_RULESET = 'reaction-proof-v5';
    public const MAX_EVENTS = 10_000;

    public const EVENT_TARGET = 0;
    public const EVENT_HIT = 1;
    public const EVENT_MISS = 2;
    public const EVENT_DECOY_ACTIVATE = 3;
    public const EVENT_DECOY_EXPIRE = 4;
    public const EVENT_FINISH = 5;
    public const EVENT_DECOY_TICK = 6;
    public const EVENT_POWER_UP_ACTIVATE = 7;
    public const EVENT_POWER_UP_CLAIM = 8;
    public const EVENT_POWER_UP_EXPIRE = 9;
    public const EVENT_POWER_UP_TICK = 10;

    public const MISS_EMPTY = 0;
    public const MISS_WRONG = 1;
    public const MISS_LATE = 2;

    public function __construct(
        public string $runId,
        public string $mode,
        public string $buildId,
        public string $ruleset,
        public int $proofVersion,
        public array $events,
    ) {
    }

    public static function fromArray(array $input): self
    {
        $allowedKeys = ['runId', 'mode', 'buildId', 'ruleset', 'proofVersion', 'events'];
        $unknownKeys = array_values(array_diff(array_keys($input), $allowedKeys));
        if ($unknownKeys !== []) {
            throw new ApiException(400, 'Run proof contains unsupported fields.');
        }

        $rawRunId = $input['runId'] ?? null;
        if (!is_string($rawRunId) || !Uuid::isValidV4($rawRunId)) {
            throw new ApiException(400, 'Run ID is invalid.');
        }

        $mode = $input['mode'] ?? null;
        if ($mode !== 'normal') {
            throw new ApiException(400, 'Mode must be normal.');
        }

        $buildId = $input['buildId'] ?? null;
        if (!self::isSupportedBuildId($buildId)) {
            throw new ApiException(409, 'This game build is no longer eligible for verified results.');
        }
        $ruleset = $input['ruleset'] ?? null;
        $proofVersion = $input['proofVersion'] ?? null;
        if (!self::supportsContract($buildId, $ruleset, $proofVersion)) {
            throw new ApiException(400, 'Run proof contract is invalid.');
        }

        $events = $input['events'] ?? null;
        if (!is_array($events) || !array_is_list($events) || $events === []) {
            throw new ApiException(400, 'Run proof events are invalid.');
        }
        if (count($events) > self::MAX_EVENTS) {
            throw new ApiException(413, 'Run proof contains too many events.');
        }

        $normalized = [];
        foreach ($events as $index => $event) {
            $normalized[] = self::normalizeEvent($event, $index, $proofVersion === self::POWER_UP_PROOF_VERSION);
        }

        return new self(
            runId: strtolower($rawRunId),
            mode: $mode,
            buildId: $buildId,
            ruleset: $ruleset,
            proofVersion: $proofVersion,
            events: $normalized,
        );
    }

    public static function isSupportedBuildId(mixed $buildId): bool
    {
        return ClientBuild::isSupported($buildId);
    }

    public static function ticketContract(
        mixed $buildId,
        mixed $ruleset = self::RULESET,
        mixed $proofVersion = self::PROOF_VERSION,
    ): ?array
    {
        if (!self::supportsContract($buildId, $ruleset, $proofVersion)) {
            return null;
        }
        return [
            'ruleset' => $ruleset,
            'proofVersion' => $proofVersion,
        ];
    }

    /** Preserve absence as legacy v3, but never silently repair partial fields. */
    public static function requestedContract(array $input): array
    {
        $hasRuleset = array_key_exists('ruleset', $input);
        $hasProofVersion = array_key_exists('proofVersion', $input);
        if ($hasRuleset !== $hasProofVersion) {
            throw new ApiException(400, 'Ranked run contract must include both ruleset and proofVersion.');
        }
        return [
            'ruleset' => $hasRuleset ? $input['ruleset'] : self::RULESET,
            'proofVersion' => $hasProofVersion ? $input['proofVersion'] : self::PROOF_VERSION,
        ];
    }

    public static function supportsContract(
        mixed $buildId,
        mixed $ruleset,
        mixed $proofVersion,
    ): bool {
        return self::isSupportedBuildId($buildId)
            && (($ruleset === self::RULESET && $proofVersion === self::PROOF_VERSION)
                || (in_array($ruleset, [self::POWER_UP_RULESET, self::FOUR_BY_FOUR_POWER_UP_RULESET], true)
                    && $proofVersion === self::POWER_UP_PROOF_VERSION));
    }

    public function hasPowerUps(): bool
    {
        return in_array($this->ruleset, [self::POWER_UP_RULESET, self::FOUR_BY_FOUR_POWER_UP_RULESET], true)
            && $this->proofVersion === self::POWER_UP_PROOF_VERSION;
    }

    public function minimumPickupGridDimension(): int
    {
        return $this->ruleset === self::FOUR_BY_FOUR_POWER_UP_RULESET ? 4 : 2;
    }

    public function eventCount(): int
    {
        return count($this->events);
    }

    public function canonicalJson(): string
    {
        return json_encode([
            'runId' => $this->runId,
            'mode' => $this->mode,
            'buildId' => $this->buildId,
            'ruleset' => $this->ruleset,
            'proofVersion' => $this->proofVersion,
            'events' => $this->events,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function proofHash(): string
    {
        return hash('sha256', $this->canonicalJson(), true);
    }

    public function traceHash(): string
    {
        $trace = [
            'mode' => $this->mode,
            'events' => $this->semanticEvents(),
        ];
        // Preserve v3/v4 hashes byte-for-byte. Each explicitly selected ruleset
        // keeps its own duplicate-trace namespace, even with shared tuple shapes.
        if ($this->hasPowerUps()) {
            $trace['ruleset'] = $this->ruleset;
            $trace['proofVersion'] = $this->proofVersion;
        }
        return hash('sha256', json_encode($trace, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
    }

    private function semanticEvents(): array
    {
        return array_map(static function (array $event): array {
            $colorPosition = match ($event[0] ?? null) {
                self::EVENT_TARGET => 3,
                self::EVENT_HIT, self::EVENT_DECOY_ACTIVATE => 4,
                default => null,
            };
            if ($colorPosition !== null) {
                unset($event[$colorPosition]);
            }
            return array_values($event);
        }, $this->events);
    }

    private static function normalizeEvent(mixed $value, int $index, bool $powerUps): array
    {
        if (!is_array($value) || !array_is_list($value) || !isset($value[0]) || !is_int($value[0])) {
            throw self::invalidEvent($index);
        }

        $type = $value[0];
        $length = count($value);
        $validLength = match ($type) {
            self::EVENT_TARGET => $length === 4,
            self::EVENT_HIT => $length === 5,
            self::EVENT_MISS => $length === 5,
            self::EVENT_DECOY_ACTIVATE => $length === 6,
            self::EVENT_DECOY_EXPIRE => $length >= 3,
            self::EVENT_FINISH => $length === 3,
            self::EVENT_DECOY_TICK => $length === 2,
            self::EVENT_POWER_UP_ACTIVATE => $powerUps && $length === 6,
            self::EVENT_POWER_UP_CLAIM => $powerUps && $length === 5,
            self::EVENT_POWER_UP_EXPIRE => $powerUps && $length === 3,
            self::EVENT_POWER_UP_TICK => $powerUps && $length === 2,
            default => false,
        };
        if (!$validLength) {
            throw self::invalidEvent($index);
        }

        foreach ($value as $part => $number) {
            if (!is_int($number)) {
                throw self::invalidEvent($index, $part);
            }
        }

        if ($type === self::EVENT_DECOY_EXPIRE) {
            $ids = array_slice($value, 2);
            if (count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
                throw self::invalidEvent($index);
            }
        }

        return array_values($value);
    }

    private static function invalidEvent(int $index, ?int $part = null): ApiException
    {
        $location = $part === null ? (string) $index : $index . ':' . $part;
        return new ApiException(400, 'Run proof event ' . $location . ' is invalid.');
    }
}
