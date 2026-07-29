<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class MultiplayerCatalog
{
    public const MODE_OWN_COLOR = 'own_color';
    public const RULESET_ID = 'multiplayer-own-color-v1';
    public const PROTOCOL_VERSION = 1;
    public const PROOF_VERSION = 1;
    public const MIN_PLAYERS = 2;
    public const MAX_PLAYERS = 4;
    public const STARTING_LIVES = 3;
    public const COLOR_COUNT = 6;
    public const MAX_DURATION_MS = 15 * 60 * 1_000;
    public const MAX_EVENTS = 2_500;
    public const SCORE_FLOOR = 100;
    public const SCORE_CEILING = 1_000;
    public const DODGE_POINTS = 550;
    public const STREAK_TARGET = 5;
    public const MAX_MULTIPLIER = 5;

    public static function supportsBuildId(mixed $buildId): bool
    {
        return is_string($buildId) && hash_equals(RunProof::BUILD_ID, $buildId);
    }
}
