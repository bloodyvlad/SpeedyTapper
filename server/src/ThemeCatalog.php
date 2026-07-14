<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class ThemeCatalog
{
    /** @var list<array{id: string, name: string, priceCoins: int}> */
    private const THEMES = [
        ['id' => 'classic', 'name' => 'Default', 'priceCoins' => 0],
        ['id' => 'disco', 'name' => 'Disco', 'priceCoins' => 0],
        ['id' => 'light', 'name' => 'Light', 'priceCoins' => 50],
        ['id' => 'pixel', 'name' => 'Pixel', 'priceCoins' => 100],
    ];

    /** @return list<array{id: string, name: string, priceCoins: int}> */
    public static function all(): array
    {
        return self::THEMES;
    }

    /** @return array{id: string, name: string, priceCoins: int} */
    public static function require(mixed $themeId): array
    {
        if (!is_string($themeId)) {
            throw new ApiException(400, 'Choose a theme.');
        }
        foreach (self::THEMES as $theme) {
            if (hash_equals($theme['id'], $themeId)) {
                return $theme;
            }
        }
        throw new ApiException(400, 'Choose an available theme.');
    }

    public static function includes(mixed $themeId): bool
    {
        if (!is_string($themeId)) return false;
        foreach (self::THEMES as $theme) {
            if (hash_equals($theme['id'], $themeId)) return true;
        }
        return false;
    }

    public static function isFree(mixed $themeId): bool
    {
        return self::require($themeId)['priceCoins'] === 0;
    }
}
