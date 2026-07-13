<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class Nickname
{
    public const MAX_LENGTH = 20;

    public static function normalize(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ApiException(400, 'Enter a nickname.');
        }

        $normalized = class_exists('Normalizer')
            ? (\Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value)
            : $value;
        $normalized = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $normalized) ?? '';
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';
        $normalized = trim($normalized);

        if ($normalized === '') {
            throw new ApiException(400, 'Enter a nickname.');
        }
        if (mb_strlen($normalized, 'UTF-8') > self::MAX_LENGTH) {
            throw new ApiException(400, 'Nicknames can have at most 20 characters.');
        }

        return $normalized;
    }

    public static function anonymous(): string
    {
        return 'Player ' . random_int(1000, 9999);
    }
}
