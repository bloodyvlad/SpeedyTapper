<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class ThemeShopService
{
    public function __construct(
        private readonly PDO $database,
        private readonly CoinWalletRepository $wallets,
    )
    {
    }

    /** @return array{ownedThemeIds: list<string>, selectedThemeId: string} */
    public function state(string $playerId): array
    {
        $owned = $this->database->prepare(
            'SELECT theme_id FROM player_themes WHERE player_id = :player_id ORDER BY acquired_at, theme_id'
        );
        $owned->execute(['player_id' => $playerId]);
        $ownedThemeIds = ['classic', 'disco'];
        foreach ($owned->fetchAll(PDO::FETCH_COLUMN) as $themeId) {
            if (ThemeCatalog::includes($themeId) && !in_array((string) $themeId, $ownedThemeIds, true)) {
                $ownedThemeIds[] = (string) $themeId;
            }
        }

        $selected = $this->database->prepare(
            'SELECT theme_id FROM player_theme_selection WHERE player_id = :player_id LIMIT 1'
        );
        $selected->execute(['player_id' => $playerId]);
        $selectedThemeId = $selected->fetchColumn();
        if (!is_string($selectedThemeId) || !in_array($selectedThemeId, $ownedThemeIds, true)) {
            $selectedThemeId = 'classic';
        }

        return [
            'ownedThemeIds' => $ownedThemeIds,
            'selectedThemeId' => $selectedThemeId,
        ];
    }

    /**
     * @return array{theme: array{id: string, name: string, priceCoins: int}, purchased: bool, pricePaid: int, coinBalance: int}
     */
    public function select(string $playerId, mixed $themeId): array
    {
        return $this->selectInTransaction($playerId, ThemeCatalog::require($themeId), true);
    }

    /**
     * @param array{id: string, name: string, priceCoins: int} $theme
     * @return array{theme: array{id: string, name: string, priceCoins: int}, purchased: bool, pricePaid: int, coinBalance: int}
     */
    private function selectInTransaction(string $playerId, array $theme, bool $mayRetry): array
    {
        $this->database->beginTransaction();
        try {
            $player = $this->wallets->lock($playerId);
            $ownership = $theme['priceCoins'] === 0
                ? ['price_paid' => 0]
                : $this->findOwnership($playerId, $theme['id']);
            $purchased = !is_array($ownership);
            $pricePaid = 0;
            $coinBalance = (int) $player['coins'];

            if ($purchased) {
                $pricePaid = $theme['priceCoins'];
                $spend = $this->wallets->spend(
                    $playerId,
                    $pricePaid,
                    'theme_purchase',
                    'theme:' . $playerId . ':' . $theme['id'] . ':g' . $player['economy_generation'],
                    'theme_purchase',
                    'theme-shop',
                    'Purchased theme ' . $theme['id'] . '.',
                    $player,
                );
                $coinBalance = $spend['coins'];

                $insert = $this->database->prepare(
                    'INSERT INTO player_themes (player_id, theme_id, price_paid, purchase_event_id) '
                    . 'VALUES (:player_id, :theme_id, :price_paid, :purchase_event_id)'
                );
                $insert->execute([
                    'player_id' => $playerId,
                    'theme_id' => $theme['id'],
                    'price_paid' => $pricePaid,
                    'purchase_event_id' => $spend['eventId'],
                ]);
            }

            $select = $this->database->prepare(
                'INSERT INTO player_theme_selection (player_id, theme_id) '
                . 'VALUES (:player_id, :theme_id) '
                . 'ON DUPLICATE KEY UPDATE theme_id = VALUES(theme_id), selected_at = UTC_TIMESTAMP(3)'
            );
            $select->execute([
                'player_id' => $playerId,
                'theme_id' => $theme['id'],
            ]);

            $this->database->commit();
            return [
                'theme' => $theme,
                'purchased' => $purchased,
                'pricePaid' => $pricePaid,
                'coinBalance' => $coinBalance,
            ];
        } catch (PDOException $error) {
            $this->rollBack();
            if ($mayRetry && $error->getCode() === '40001') {
                return $this->selectInTransaction($playerId, $theme, false);
            }
            throw $error;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    private function findOwnership(string $playerId, string $themeId): array|false
    {
        $statement = $this->database->prepare(
            'SELECT price_paid FROM player_themes '
            . 'WHERE player_id = :player_id AND theme_id = :theme_id FOR UPDATE'
        );
        $statement->execute(['player_id' => $playerId, 'theme_id' => $themeId]);
        return $statement->fetch();
    }

    private function rollBack(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }
}
