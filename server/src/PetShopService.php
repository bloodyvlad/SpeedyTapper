<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class PetShopService
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{ownedPetIds: list<string>, equippedPetId: ?string} */
    public function state(string $playerId): array
    {
        $owned = $this->database->prepare(
            'SELECT pet_id FROM player_pets WHERE player_id = :player_id ORDER BY acquired_at, pet_id'
        );
        $owned->execute(['player_id' => $playerId]);
        $ownedPetIds = array_values(array_filter(
            array_map('strval', $owned->fetchAll(PDO::FETCH_COLUMN)),
            static fn (string $petId): bool => PetCatalog::includes($petId),
        ));

        $selected = $this->database->prepare(
            'SELECT pet_id FROM player_pet_selection WHERE player_id = :player_id LIMIT 1'
        );
        $selected->execute(['player_id' => $playerId]);
        $equipped = $selected->fetchColumn();
        $equippedPetId = is_string($equipped) && in_array($equipped, $ownedPetIds, true)
            ? $equipped
            : null;

        return [
            'ownedPetIds' => $ownedPetIds,
            'equippedPetId' => $equippedPetId,
        ];
    }

    /**
     * @return array{pet: array{id: string, name: string, priceCoins: int}, purchased: bool, pricePaid: int, coinBalance: int}
     */
    public function select(string $playerId, mixed $petId): array
    {
        return $this->selectInTransaction($playerId, PetCatalog::require($petId), true);
    }

    /**
     * @param array{id: string, name: string, priceCoins: int} $pet
     * @return array{pet: array{id: string, name: string, priceCoins: int}, purchased: bool, pricePaid: int, coinBalance: int}
     */
    private function selectInTransaction(string $playerId, array $pet, bool $mayRetry): array
    {
        $this->database->beginTransaction();
        try {
            $player = $this->lockPlayer($playerId);
            $ownership = $this->findOwnership($playerId, $pet['id']);
            $purchased = !is_array($ownership);
            $pricePaid = 0;
            $coinBalance = (int) $player['coins'];

            if ($purchased) {
                $pricePaid = $pet['priceCoins'];
                if ($coinBalance < $pricePaid) {
                    $missing = $pricePaid - $coinBalance;
                    throw new ApiException(
                        409,
                        sprintf(
                            'You need %d more %s to buy %s.',
                            $missing,
                            $missing === 1 ? 'coin' : 'coins',
                            $pet['name'],
                        ),
                    );
                }

                $debit = $this->database->prepare(
                    'UPDATE players SET coins = coins - :price, updated_at = UTC_TIMESTAMP(3) '
                    . 'WHERE id = :player_id AND coins >= :price'
                );
                $debit->execute([
                    'price' => $pricePaid,
                    'player_id' => $playerId,
                ]);
                if ($debit->rowCount() !== 1) {
                    throw new ApiException(409, 'Your coin balance changed. Try again.');
                }
                $coinBalance -= $pricePaid;

                $insert = $this->database->prepare(
                    'INSERT INTO player_pets '
                    . '(player_id, pet_id, price_paid, acquisition_source) '
                    . "VALUES (:player_id, :pet_id, :price_paid, 'purchase')"
                );
                $insert->execute([
                    'player_id' => $playerId,
                    'pet_id' => $pet['id'],
                    'price_paid' => $pricePaid,
                ]);
            }

            $select = $this->database->prepare(
                'INSERT INTO player_pet_selection (player_id, pet_id) VALUES (:player_id, :pet_id) '
                . 'ON DUPLICATE KEY UPDATE pet_id = VALUES(pet_id), equipped_at = UTC_TIMESTAMP(3)'
            );
            $select->execute([
                'player_id' => $playerId,
                'pet_id' => $pet['id'],
            ]);

            $this->database->commit();
            return [
                'pet' => $pet,
                'purchased' => $purchased,
                'pricePaid' => $pricePaid,
                'coinBalance' => $coinBalance,
            ];
        } catch (PDOException $error) {
            $this->rollBack();
            if ($mayRetry && $error->getCode() === '40001') {
                return $this->selectInTransaction($playerId, $pet, false);
            }
            throw $error;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /** @return array{coins: int} */
    private function lockPlayer(string $playerId): array
    {
        $statement = $this->database->prepare(
            'SELECT coins FROM players WHERE id = :player_id FOR UPDATE'
        );
        $statement->execute(['player_id' => $playerId]);
        $player = $statement->fetch();
        if (!is_array($player)) {
            throw new ApiException(401, 'Sign in with Google to continue.');
        }
        return $player;
    }

    private function findOwnership(string $playerId, string $petId): array|false
    {
        $statement = $this->database->prepare(
            'SELECT price_paid FROM player_pets '
            . 'WHERE player_id = :player_id AND pet_id = :pet_id FOR UPDATE'
        );
        $statement->execute(['player_id' => $playerId, 'pet_id' => $petId]);
        return $statement->fetch();
    }

    private function rollBack(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }
}
