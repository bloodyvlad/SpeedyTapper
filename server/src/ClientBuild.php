<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class ClientBuild
{
    private const MINIMUM_DATE = '20260729';
    private const MINIMUM_SEQUENCE = '1';

    public const MINIMUM_ID = self::MINIMUM_DATE . '-' . self::MINIMUM_SEQUENCE;
    public const MAX_LENGTH = 32;

    public static function isSupported(mixed $buildId): bool
    {
        if (
            !is_string($buildId)
            || strlen($buildId) > self::MAX_LENGTH
            || preg_match('/^(\d{8})-([1-9]\d*)$/D', $buildId, $parts) !== 1
        ) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd', $parts[1]);
        if ($date === false || $date->format('Ymd') !== $parts[1]) {
            return false;
        }
        $dateComparison = strcmp($parts[1], self::MINIMUM_DATE);
        if ($dateComparison !== 0) {
            return $dateComparison > 0;
        }
        return self::compareDecimal($parts[2], self::MINIMUM_SEQUENCE) >= 0;
    }

    private static function compareDecimal(string $left, string $right): int
    {
        $lengthComparison = strlen($left) <=> strlen($right);
        return $lengthComparison !== 0 ? $lengthComparison : strcmp($left, $right);
    }
}
