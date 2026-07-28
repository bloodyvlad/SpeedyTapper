<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class PlayerRepository
{
    public function __construct(
        private readonly PDO $database,
        private readonly PetShopService $pets,
        private readonly ThemeShopService $themes,
    ) {
    }

    public function find(string $playerId): ?array
    {
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $statement = $this->database->prepare(
                'SELECT id, nickname, nickname_confirmed, coins, total_play_ms, created_at, updated_at, '
                . 'EXISTS(SELECT 1 FROM player_roles role WHERE role.player_id = players.id '
                . "AND role.role = 'leaderboard_admin') AS is_admin "
                . 'FROM players WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $playerId]);
            $row = $statement->fetch();
            $profile = is_array($row) ? $this->publicProfile($row) : null;
            if ($ownsTransaction) {
                $this->database->commit();
            }
            return $profile;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** @return null|array{id: string, nicknameConfirmed: bool} */
    public function findRunIdentity(string $playerId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, nickname_confirmed FROM players WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $playerId]);
        $row = $statement->fetch();
        return is_array($row) ? [
            'id' => (string) $row['id'],
            'nicknameConfirmed' => (bool) $row['nickname_confirmed'],
        ] : null;
    }

    public function updateNickname(string $playerId, mixed $nickname): array
    {
        $normalized = Nickname::normalize($nickname);
        $statement = $this->database->prepare(
            'UPDATE players SET nickname = :nickname, nickname_confirmed = 1, '
            . 'updated_at = UTC_TIMESTAMP(3) WHERE id = :id'
        );
        try {
            $statement->execute(['nickname' => $normalized, 'id' => $playerId]);
        } catch (PDOException $error) {
            if (self::isNicknameConflict($error)) {
                throw new ApiException(409, 'This player name is already taken.');
            }
            throw $error;
        }
        if ($statement->rowCount() === 0 && $this->find($playerId) === null) {
            throw new ApiException(401, 'Sign in again to update this profile.');
        }
        return $this->find($playerId) ?? throw new ApiException(401, 'Sign in again to update this profile.');
    }

    /** @return array{nickname: string, available: bool} */
    public function nicknameAvailability(string $playerId, mixed $nickname): array
    {
        $normalized = Nickname::normalize($nickname);
        $statement = $this->database->prepare(
            'SELECT 1 FROM players '
            . 'WHERE nickname_confirmed = 1 AND nickname = :nickname AND id <> :player_id '
            . 'LIMIT 1'
        );
        $statement->execute([
            'nickname' => $normalized,
            'player_id' => $playerId,
        ]);

        return [
            'nickname' => $normalized,
            'available' => $statement->fetchColumn() === false,
        ];
    }

    private function publicProfile(array $row): array
    {
        $petState = $this->pets->state((string) $row['id']);
        $themeState = $this->themes->state((string) $row['id']);
        $specialPetId = PetCatalog::specialForNickname(
            $row['nickname'] ?? null,
            (bool) ($row['nickname_confirmed'] ?? false),
        );
        return [
            'id' => (string) $row['id'],
            'nickname' => (string) $row['nickname'],
            'nicknameConfirmed' => (bool) $row['nickname_confirmed'],
            'coins' => (int) $row['coins'],
            'totalPlayMs' => (int) $row['total_play_ms'],
            'ownedPetIds' => $petState['ownedPetIds'],
            'selectedPetId' => $petState['selectedPetId'],
            'petVisible' => $petState['petVisible'],
            'equippedPetId' => $specialPetId ?? $petState['equippedPetId'],
            'specialPetId' => $specialPetId,
            'ownedThemeIds' => $themeState['ownedThemeIds'],
            'selectedThemeId' => $themeState['selectedThemeId'],
            'isAdmin' => (bool) ($row['is_admin'] ?? false),
            'createdAt' => self::isoDate((string) $row['created_at']),
            'updatedAt' => self::isoDate((string) $row['updated_at']),
        ];
    }

    private static function isoDate(string $value): string
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function isNicknameConflict(PDOException $error): bool
    {
        if ($error->getCode() !== '23000') {
            return false;
        }

        $details = strtolower(implode(' ', array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $error->errorInfo ?? [],
        )));
        $details .= ' ' . strtolower($error->getMessage());

        return str_contains($details, 'players_confirmed_nickname_unique')
            || str_contains($details, 'nickname_unique_key')
            || str_contains($details, 'players.nickname');
    }
}
