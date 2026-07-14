<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class PetCatalog
{
    public const MITSURI_ID = 'mitsuri';
    public const MITSURI_NICKNAME = 'кокос';

    /** @var list<array{id: string, name: string, priceCoins: int}> */
    private const PETS = [
        ['id' => 'foka', 'name' => 'Foka', 'priceCoins' => 10],
        ['id' => 'kesha', 'name' => 'Kesha', 'priceCoins' => 20],
        ['id' => 'tauta', 'name' => 'Tauta', 'priceCoins' => 50],
        ['id' => 'misha', 'name' => 'Misha', 'priceCoins' => 100],
        ['id' => 'pancake', 'name' => 'Pancake', 'priceCoins' => 500],
    ];

    /** @return list<array{id: string, name: string, priceCoins: int}> */
    public static function all(): array
    {
        return self::PETS;
    }

    /** @return array{id: string, name: string, priceCoins: int} */
    public static function require(mixed $petId): array
    {
        if (!is_string($petId)) {
            throw new ApiException(400, 'Choose a pet.');
        }
        foreach (self::PETS as $pet) {
            if (hash_equals($pet['id'], $petId)) {
                return $pet;
            }
        }
        throw new ApiException(400, 'Choose an available pet.');
    }

    public static function includes(mixed $petId): bool
    {
        if (!is_string($petId)) return false;
        foreach (self::PETS as $pet) {
            if (hash_equals($pet['id'], $petId)) return true;
        }
        return false;
    }

    public static function specialForNickname(mixed $nickname, bool $nicknameConfirmed): ?string
    {
        if (!$nicknameConfirmed || !is_string($nickname)) {
            return null;
        }
        return hash_equals(self::MITSURI_NICKNAME, $nickname) ? self::MITSURI_ID : null;
    }

    public static function isRenderable(mixed $petId): bool
    {
        return self::includes($petId) || $petId === self::MITSURI_ID;
    }
}
