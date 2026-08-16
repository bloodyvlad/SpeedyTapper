<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class ClientBuild
{
    public const MINIMUM_ID = '20260729-1';

    public static function isSupported(mixed $buildId): bool
    {
        if (!is_string($buildId) || preg_match('/^(\d{8})-([1-9]\d*)$/D', $buildId, $parts) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd', $parts[1]);
        if ($date === false || $date->format('Ymd') !== $parts[1]) {
            return false;
        }
        return [(int) $parts[1], (int) $parts[2]] >= [20260729, 1];
    }
}
