<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class PetShopService
{
    public function __construct(
        private readonly PDO $database,
        private readonly AchievementService $achievements,
        private readonly CoinWalletRepository $wallets,
    ) {
    }

    /**
     * @return array{
     *   ownedPetIds: list<string>,
     *   selectedPetId: ?string,
     *   petVisible: bool,
     *   equippedPetId: ?string
     * }
     */
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
            'SELECT pet_id, is_visible FROM player_pet_selection WHERE player_id = :player_id LIMIT 1'
        );
        $selected->execute(['player_id' => $playerId]);
        $selection = $selected->fetch();
        $selectedPetId = is_array($selection)
            && is_string($selection['pet_id'] ?? null)
            && in_array($selection['pet_id'], $ownedPetIds, true)
            ? $selection['pet_id']
            : null;
        $petVisible = $selectedPetId !== null && (bool) ($selection['is_visible'] ?? false);

        return [
            'ownedPetIds' => $ownedPetIds,
            'selectedPetId' => $selectedPetId,
            'petVisible' => $petVisible,
            'equippedPetId' => $petVisible ? $selectedPetId : null,
        ];
    }

    /**
     * @return array{pet: array{id: string, name: string, priceCoins: int}, purchased: bool, pricePaid: int, coinBalance: int}
     */
    public function select(string $playerId, mixed $petId): array
    {
        return $this->selectInTransaction($playerId, PetCatalog::require($petId), true);
    }

    /** @return array{pet: array{id: string, name: string, priceCoins: int}, visible: bool} */
    public function setVisibility(string $playerId, mixed $petId, mixed $visible): array
    {
        $pet = PetCatalog::require($petId);
        if (!is_bool($visible)) {
            throw new ApiException(400, 'Choose whether to show or hide the pet.');
        }

        $statement = $this->database->prepare(
            'UPDATE player_pet_selection SET is_visible = :is_visible, equipped_at = UTC_TIMESTAMP(3) '
            . 'WHERE player_id = :player_id AND pet_id = :pet_id'
        );
        $statement->bindValue('is_visible', $visible ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('player_id', $playerId);
        $statement->bindValue('pet_id', $pet['id']);
        $statement->execute();

        $state = $this->state($playerId);
        if ($state['selectedPetId'] !== $pet['id']) {
            throw new ApiException(409, 'Your selected pet changed. Try again.');
        }

        return ['pet' => $pet, 'visible' => $state['petVisible']];
    }

    /**
     * @param array{id: string, name: string, priceCoins: int} $pet
     * @return array{pet: array{id: string, name: string, priceCoins: int}, purchased: bool, pricePaid: int, coinBalance: int}
     */
    private function selectInTransaction(string $playerId, array $pet, bool $mayRetry): array
    {
        $this->database->beginTransaction();
        try {
            $player = $this->wallets->lock($playerId);
            $ownership = $this->findOwnership($playerId, $pet['id']);
            $purchased = !is_array($ownership);
            $pricePaid = 0;
            $coinBalance = (int) $player['coins'];

            if ($purchased) {
                $pricePaid = $pet['priceCoins'];
                $spend = $this->wallets->spend(
                    $playerId,
                    $pricePaid,
                    'pet_purchase',
                    'pet:' . $playerId . ':' . $pet['id'] . ':g' . $player['economy_generation'],
                    'pet_purchase',
                    'pet-shop',
                    'Purchased pet ' . $pet['id'] . '.',
                    $player,
                );
                $coinBalance = $spend['coins'];

                $insert = $this->database->prepare(
                    'INSERT INTO player_pets '
                    . '(player_id, pet_id, price_paid, acquisition_source, purchase_event_id) '
                    . "VALUES (:player_id, :pet_id, :price_paid, 'purchase', :purchase_event_id)"
                );
                $insert->execute([
                    'player_id' => $playerId,
                    'pet_id' => $pet['id'],
                    'price_paid' => $pricePaid,
                    'purchase_event_id' => $spend['eventId'],
                ]);
                $this->achievements->unlockBuyPetInTransaction($playerId);
            }

            $select = $this->database->prepare(
                'INSERT INTO player_pet_selection (player_id, pet_id, is_visible) '
                . 'VALUES (:player_id, :pet_id, 1) '
                . 'ON DUPLICATE KEY UPDATE pet_id = VALUES(pet_id), is_visible = 1, '
                . 'equipped_at = UTC_TIMESTAMP(3)'
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
