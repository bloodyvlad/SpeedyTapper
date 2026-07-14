<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class ThemeShopService
{
    public function __construct(private readonly PDO $database)
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
            $player = $this->lockPlayer($playerId);
            $ownership = $theme['priceCoins'] === 0
                ? ['price_paid' => 0]
                : $this->findOwnership($playerId, $theme['id']);
            $purchased = !is_array($ownership);
            $pricePaid = 0;
            $coinBalance = (int) $player['coins'];

            if ($purchased) {
                $pricePaid = $theme['priceCoins'];
                if ($coinBalance < $pricePaid) {
                    $missing = $pricePaid - $coinBalance;
                    throw new ApiException(
                        409,
                        sprintf(
                            'You need %d more %s to buy %s.',
                            $missing,
                            $missing === 1 ? 'coin' : 'coins',
                            $theme['name'],
                        ),
                    );
                }

                $debit = $this->database->prepare(
                    'UPDATE players SET coins = coins - :price, updated_at = UTC_TIMESTAMP(3) '
                    . 'WHERE id = :player_id AND coins >= :minimum_balance'
                );
                $debit->execute([
                    'price' => $pricePaid,
                    'minimum_balance' => $pricePaid,
                    'player_id' => $playerId,
                ]);
                if ($debit->rowCount() !== 1) {
                    throw new ApiException(409, 'Your coin balance changed. Try again.');
                }
                $coinBalance -= $pricePaid;

                $insert = $this->database->prepare(
                    'INSERT INTO player_themes (player_id, theme_id, price_paid) '
                    . 'VALUES (:player_id, :theme_id, :price_paid)'
                );
                $insert->execute([
                    'player_id' => $playerId,
                    'theme_id' => $theme['id'],
                    'price_paid' => $pricePaid,
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

            if ($purchased) {
                $this->insertPurchaseLedger(
                    $playerId,
                    (int) $player['economy_generation'],
                    $theme['id'],
                    $pricePaid,
                    $coinBalance,
                    (int) $player['coin_debt'],
                    (int) $player['total_play_ms'],
                );
            }

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

    /** @return array{coins: int, coin_debt: int, total_play_ms: int, economy_generation: int} */
    private function lockPlayer(string $playerId): array
    {
        $statement = $this->database->prepare(
            'SELECT coins, coin_debt, total_play_ms, economy_generation '
            . 'FROM players WHERE id = :player_id FOR UPDATE'
        );
        $statement->execute(['player_id' => $playerId]);
        $player = $statement->fetch();
        if (!is_array($player)) {
            throw new ApiException(401, 'Sign in with Google to continue.');
        }
        return $player;
    }

    private function insertPurchaseLedger(
        string $playerId,
        int $economyGeneration,
        string $themeId,
        int $pricePaid,
        int $coinBalance,
        int $coinDebt,
        int $totalPlayMs,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO coin_ledger '
            . '(event_id, event_key, player_id, economy_generation, event_type, play_ms_delta, coin_delta, '
            . 'coin_balance_after, coin_debt_after, total_play_ms_after, coin_status, actor, reason) '
            . "VALUES (:event_id, :event_key, :player_id, :economy_generation, 'theme_purchase', 0, :coin_delta, "
            . ":coin_balance_after, :coin_debt_after, :total_play_ms_after, 'eligible', "
            . "'theme-shop', :reason)"
        );
        $statement->execute([
            'event_id' => Uuid::v4(),
            'event_key' => 'theme:' . $playerId . ':' . $themeId . ':g' . $economyGeneration,
            'player_id' => $playerId,
            'economy_generation' => $economyGeneration,
            'coin_delta' => -$pricePaid,
            'coin_balance_after' => $coinBalance,
            'coin_debt_after' => $coinDebt,
            'total_play_ms_after' => $totalPlayMs,
            'reason' => 'Purchased theme ' . $themeId . '.',
        ]);
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
