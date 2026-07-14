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
    public const BUILD_ID = '20260714-10';
    public const RULESET = 'reaction-proof-v2';
    public const PROOF_VERSION = 1;
    public const MAX_EVENTS = 10_000;

    public const EVENT_TARGET = 0;
    public const EVENT_HIT = 1;
    public const EVENT_MISS = 2;
    public const EVENT_DECOY_ACTIVATE = 3;
    public const EVENT_DECOY_EXPIRE = 4;
    public const EVENT_FINISH = 5;
    public const EVENT_DECOY_TICK = 6;

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
        if ($mode !== 'normal' && $mode !== 'zen') {
            throw new ApiException(400, 'Mode must be normal or zen.');
        }

        if (($input['buildId'] ?? null) !== self::BUILD_ID) {
            throw new ApiException(409, 'This game build is no longer eligible for verified results.');
        }
        if (($input['ruleset'] ?? null) !== self::RULESET) {
            throw new ApiException(400, 'Run proof ruleset is invalid.');
        }
        if (($input['proofVersion'] ?? null) !== self::PROOF_VERSION) {
            throw new ApiException(400, 'Run proof version is invalid.');
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
            $normalized[] = self::normalizeEvent($event, $index);
        }

        return new self(
            runId: strtolower($rawRunId),
            mode: $mode,
            buildId: self::BUILD_ID,
            ruleset: self::RULESET,
            proofVersion: self::PROOF_VERSION,
            events: $normalized,
        );
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
        // Keep exact-event replay detection stable across browser releases. A
        // build identifier belongs in the run-bound proof hash, but including
        // it here would let a copied trace earn rewards again after every deploy.
        return hash('sha256', json_encode([
            'mode' => $this->mode,
            'events' => $this->events,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
    }

    private static function normalizeEvent(mixed $value, int $index): array
    {
        if (!is_array($value) || !array_is_list($value) || !isset($value[0]) || !is_int($value[0])) {
            throw self::invalidEvent($index);
        }

        $type = $value[0];
        $length = count($value);
        $validLength = match ($type) {
            self::EVENT_TARGET, self::EVENT_FINISH => $length === 3,
            self::EVENT_HIT => $length === 4,
            self::EVENT_MISS, self::EVENT_DECOY_ACTIVATE => $length === 5,
            self::EVENT_DECOY_EXPIRE => $length >= 3,
            self::EVENT_DECOY_TICK => $length === 2,
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
