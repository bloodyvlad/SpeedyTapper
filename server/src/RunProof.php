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
    public const LEGACY_RULESET = 'reaction-proof-v2';
    public const LEGACY_PROOF_VERSION = 1;
    public const RULESET = 'reaction-proof-v3';
    public const PROOF_VERSION = 2;
    public const SUPPORTED_BUILD_IDS = [
        '20260718-1',
        '20260719-1',
        '20260719-2',
        '20260719-3',
        '20260720-1',
        '20260725-1',
        '20260727-1',
        '20260727-2',
        '20260727-3',
        '20260728-2',
        '20260729-1',
        self::BUILD_ID,
    ];
    public const MAX_EVENTS = 10_000;
    private const COLOR_PROOF_BUILD_IDS = [
        '20260729-1',
        self::BUILD_ID,
    ];
    private const PERSISTENT_DECOY_BUILD_IDS = [
        '20260729-1',
        self::BUILD_ID,
    ];

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
            $normalized[] = self::normalizeEvent(
                $event,
                $index,
                $ruleset === self::RULESET && $proofVersion === self::PROOF_VERSION,
            );
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
        return is_string($buildId) && in_array($buildId, self::SUPPORTED_BUILD_IDS, true);
    }

    public static function ticketContract(mixed $buildId): ?array
    {
        if (!self::isSupportedBuildId($buildId)) {
            return null;
        }
        if (self::usesColorProofRules($buildId)) {
            return [
                'ruleset' => self::RULESET,
                'proofVersion' => self::PROOF_VERSION,
            ];
        }
        return [
            'ruleset' => self::LEGACY_RULESET,
            'proofVersion' => self::LEGACY_PROOF_VERSION,
        ];
    }

    public static function supportsContract(
        mixed $buildId,
        mixed $ruleset,
        mixed $proofVersion,
    ): bool {
        if (!self::isSupportedBuildId($buildId) || !is_string($ruleset) || !is_int($proofVersion)) {
            return false;
        }
        if (
            self::usesColorProofRules($buildId)
            && $ruleset === self::RULESET
            && $proofVersion === self::PROOF_VERSION
        ) {
            return true;
        }

        // Release 20260729-1 was briefly issued to the browser as v2/1 before
        // the already-distributed native v3/2 contract was restored. Parsing
        // that combination lets only already-issued v2/1 attempts complete;
        // new tickets use ticketContract() and are always v3/2.
        $legacyBuild = $buildId !== self::BUILD_ID;
        return $legacyBuild
            && $ruleset === self::LEGACY_RULESET
            && $proofVersion === self::LEGACY_PROOF_VERSION;
    }

    public static function usesColorProofRules(mixed $buildId): bool
    {
        return is_string($buildId)
            && in_array($buildId, self::COLOR_PROOF_BUILD_IDS, true);
    }

    public static function usesPersistentDecoyRules(mixed $buildId): bool
    {
        return is_string($buildId)
            && in_array($buildId, self::PERSISTENT_DECOY_BUILD_IDS, true);
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
        // Keep replay detection stable across builds and proof generations.
        // V3 color fields remain bound by proofHash(), but cannot make the same
        // timing/cell trace eligible for rewards again after a contract update.
        return hash('sha256', json_encode([
            'mode' => $this->mode,
            'events' => array_map(
                static fn (array $event): array => match ($event[0]) {
                    self::EVENT_TARGET => array_slice($event, 0, 3),
                    self::EVENT_HIT => array_slice($event, 0, 4),
                    self::EVENT_DECOY_ACTIVATE => [
                        $event[0],
                        $event[1],
                        $event[2],
                        $event[3],
                        $event[count($event) - 1],
                    ],
                    default => $event,
                },
                $this->events,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
    }

    private static function normalizeEvent(
        mixed $value,
        int $index,
        bool $colorProofRules,
    ): array
    {
        if (!is_array($value) || !array_is_list($value) || !isset($value[0]) || !is_int($value[0])) {
            throw self::invalidEvent($index);
        }

        $type = $value[0];
        $length = count($value);
        $validLength = match ($type) {
            self::EVENT_TARGET => $length === ($colorProofRules ? 4 : 3),
            self::EVENT_HIT => $length === ($colorProofRules ? 5 : 4),
            self::EVENT_MISS => $length === 5,
            self::EVENT_DECOY_ACTIVATE => $length === ($colorProofRules ? 6 : 5),
            self::EVENT_DECOY_EXPIRE => $length >= 3,
            self::EVENT_FINISH => $length === 3,
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
