<?php

declare(strict_types=1);

namespace SpeedyTapper;

/**
 * Compact seat-only transcript shared by every participant in one GKMatch.
 *
 * It intentionally contains no internal player UUID, nickname, pet, provider
 * identifier, session material, or raw Game Center identifier. PHP maps seats
 * to authenticated participants from its own match manifest.
 */
final readonly class MultiplayerTranscript
{
    public const EVENT_TARGET = 0;
    public const EVENT_HIT = 1;
    public const EVENT_MISS = 2;
    public const EVENT_DECOY_ACTIVATE = 3;
    public const EVENT_DECOY_EXPIRE = 4;
    public const EVENT_PLAYER_OUT = 5;
    public const EVENT_FINISH = 6;

    public const MISS_EMPTY = 0;
    public const MISS_WRONG = 1;
    public const MISS_LATE = 2;

    public function __construct(
        public string $matchId,
        public string $buildId,
        public string $ruleset,
        public int $protocolVersion,
        public int $proofVersion,
        public array $events,
    ) {
    }

    public static function fromArray(array $input): self
    {
        $allowed = [
            'matchId',
            'buildId',
            'ruleset',
            'protocolVersion',
            'proofVersion',
            'events',
        ];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiException(400, 'Multiplayer transcript contains unsupported fields.');
        }
        $matchId = $input['matchId'] ?? null;
        if (!is_string($matchId) || !Uuid::isValidV4($matchId)) {
            throw new ApiException(400, 'Multiplayer match ID is invalid.');
        }
        $buildId = $input['buildId'] ?? null;
        if (!MultiplayerCatalog::supportsBuildId($buildId)) {
            throw new ApiException(
                409,
                'This game build is no longer eligible for multiplayer results.',
            );
        }
        if (($input['ruleset'] ?? null) !== MultiplayerCatalog::RULESET_ID) {
            throw new ApiException(400, 'Multiplayer transcript ruleset is invalid.');
        }
        if (($input['protocolVersion'] ?? null) !== MultiplayerCatalog::PROTOCOL_VERSION) {
            throw new ApiException(400, 'Multiplayer protocol version is invalid.');
        }
        if (($input['proofVersion'] ?? null) !== MultiplayerCatalog::PROOF_VERSION) {
            throw new ApiException(400, 'Multiplayer proof version is invalid.');
        }
        $events = $input['events'] ?? null;
        if (!is_array($events) || !array_is_list($events) || $events === []) {
            throw new ApiException(400, 'Multiplayer transcript events are invalid.');
        }
        if (count($events) > MultiplayerCatalog::MAX_EVENTS) {
            throw new ApiException(413, 'Multiplayer transcript contains too many events.');
        }

        $normalized = [];
        foreach ($events as $index => $event) {
            $normalized[] = self::normalizeEvent($event, $index);
        }
        return new self(
            matchId: strtolower($matchId),
            buildId: $buildId,
            ruleset: MultiplayerCatalog::RULESET_ID,
            protocolVersion: MultiplayerCatalog::PROTOCOL_VERSION,
            proofVersion: MultiplayerCatalog::PROOF_VERSION,
            events: $normalized,
        );
    }

    public function canonicalJson(): string
    {
        return json_encode([
            'matchId' => $this->matchId,
            'buildId' => $this->buildId,
            'ruleset' => $this->ruleset,
            'protocolVersion' => $this->protocolVersion,
            'proofVersion' => $this->proofVersion,
            'events' => $this->events,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonicalJson(), true);
    }

    public function traceHash(): string
    {
        // Match and build identifiers bind a submission, but they must not let
        // an identical event trace earn another leaderboard result in a fresh
        // lobby or after a release. Ruleset/protocol changes remain separate
        // replay domains.
        return hash('sha256', json_encode([
            'ruleset' => $this->ruleset,
            'protocolVersion' => $this->protocolVersion,
            'proofVersion' => $this->proofVersion,
            'events' => $this->events,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
    }

    public function eventCount(): int
    {
        return count($this->events);
    }

    private static function normalizeEvent(mixed $value, int $index): array
    {
        if (
            !is_array($value)
            || !array_is_list($value)
            || !isset($value[0], $value[1])
            || !is_int($value[0])
            || !is_int($value[1])
        ) {
            throw self::invalidEvent($index);
        }
        $expectedLength = match ($value[0]) {
            self::EVENT_TARGET => 7,
            self::EVENT_HIT => 7,
            self::EVENT_MISS => 7,
            self::EVENT_DECOY_ACTIVATE => 8,
            self::EVENT_DECOY_EXPIRE => 4,
            self::EVENT_PLAYER_OUT => 4,
            self::EVENT_FINISH => 3,
            default => 0,
        };
        if ($expectedLength === 0 || count($value) !== $expectedLength) {
            throw self::invalidEvent($index);
        }
        foreach ($value as $part => $number) {
            if (!is_int($number)) {
                throw self::invalidEvent($index, $part);
            }
        }
        return array_values($value);
    }

    private static function invalidEvent(int $index, ?int $part = null): ApiException
    {
        $location = $part === null ? (string) $index : $index . ':' . $part;
        return new ApiException(400, 'Multiplayer transcript event ' . $location . ' is invalid.');
    }
}
