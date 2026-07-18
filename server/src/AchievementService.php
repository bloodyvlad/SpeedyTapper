<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class AchievementService
{
    private ?bool $petOwnershipAvailable = null;

    public function __construct(private readonly PDO $database)
    {
    }

    public function payload(?string $playerId): array
    {
        if ($playerId === null) {
            return $this->formatPayload(null, [], 0);
        }

        $this->database->beginTransaction();
        try {
            $player = $this->lockPlayer($playerId);
            $this->syncHistoricalEligibility(
                $playerId,
                (int) $player['total_coins_collected'],
                (int) $player['economy_generation'],
            );
            $payload = $this->formatPayload(
                $playerId,
                $this->achievementRows($playerId),
                (int) $player['coins'],
            );
            $this->database->commit();
            return $payload;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    public function unlockForRunInTransaction(
        string $playerId,
        ScoreSubmission $score,
        int $totalCoinsCollected,
    ): void {
        if (!$this->database->inTransaction()) {
            throw new \LogicException('Run achievements must unlock inside the run transaction.');
        }

        if ($score->mode !== 'normal') {
            return;
        }

        $this->unlock($playerId, AchievementCatalog::COMPLETE_ARCADE);
        if ($score->godlikeCount > 0) {
            $this->unlock($playerId, AchievementCatalog::GODLIKE_SPEED);
        }
        if ($score->score > 100_000) {
            $this->unlock($playerId, AchievementCatalog::SCORE_OVER_100K);
        }
        if ($totalCoinsCollected >= 5) {
            $this->unlock($playerId, AchievementCatalog::COLLECT_FIVE_COINS);
        }
    }

    public function unlockBuyPetInTransaction(string $playerId): void
    {
        if (!$this->database->inTransaction()) {
            throw new \LogicException('Pet achievements must unlock inside the purchase transaction.');
        }
        $this->unlock($playerId, AchievementCatalog::BUY_A_PET);
    }

    public function currentPayload(string $playerId, int $coinBalance): array
    {
        return $this->formatPayload(
            $playerId,
            $this->achievementRows($playerId),
            $coinBalance,
        );
    }

    public function claim(string $playerId, mixed $achievementId): array
    {
        $definition = AchievementCatalog::require($achievementId);
        return $this->claimInTransaction($playerId, $definition, true);
    }

    private function claimInTransaction(string $playerId, array $definition, bool $mayRetry): array
    {
        $this->database->beginTransaction();
        try {
            $player = $this->lockPlayer($playerId);
            $this->syncHistoricalEligibility(
                $playerId,
                (int) $player['total_coins_collected'],
                (int) $player['economy_generation'],
            );
            $achievement = $this->lockAchievement($playerId, $definition['id']);
            if ($achievement === null) {
                throw new ApiException(409, 'Complete this achievement before claiming its coins.');
            }

            if ($achievement['claimed_at'] !== null) {
                $this->database->commit();
                return $this->claimPayload($playerId, $definition['id'], 0, true);
            }

            $rewardCoins = (int) $achievement['reward_coins'];
            if ($rewardCoins !== $definition['rewardCoins']) {
                throw new \RuntimeException('Stored achievement reward does not match the catalog.');
            }
            $wallet = CoinEconomy::applyCredit(
                (int) $player['coins'],
                (int) $player['coin_debt'],
                $rewardCoins,
            );
            $coinDebt = $wallet['debt'];
            $coinBalance = $wallet['coins'];
            $totalCoinsCollected = (int) $player['total_coins_collected'] + $rewardCoins;

            $updatePlayer = $this->database->prepare(
                'UPDATE players SET coins = :coins, coin_debt = :coin_debt, '
                . 'total_coins_collected = :total_coins_collected, '
                . 'updated_at = UTC_TIMESTAMP(3) WHERE id = :player_id'
            );
            $updatePlayer->execute([
                'coins' => $coinBalance,
                'coin_debt' => $coinDebt,
                'total_coins_collected' => $totalCoinsCollected,
                'player_id' => $playerId,
            ]);

            $claim = $this->database->prepare(
                'UPDATE player_achievements SET claimed_at = UTC_TIMESTAMP(3) '
                . 'WHERE player_id = :player_id AND achievement_key = :achievement_key '
                . 'AND claimed_at IS NULL'
            );
            $claim->execute([
                'player_id' => $playerId,
                'achievement_key' => $definition['id'],
            ]);
            if ($claim->rowCount() !== 1) {
                throw new \RuntimeException('Achievement claim could not be recorded.');
            }

            $this->insertRewardLedger(
                $playerId,
                (int) $player['economy_generation'],
                $definition['id'],
                $rewardCoins,
                $coinBalance,
                $coinDebt,
                (int) $player['total_play_ms'],
            );

            if ($totalCoinsCollected >= 5) {
                $this->unlock($playerId, AchievementCatalog::COLLECT_FIVE_COINS);
            }

            $this->database->commit();
            return $this->claimPayload(
                $playerId,
                $definition['id'],
                $rewardCoins,
                false,
            );
        } catch (PDOException $error) {
            $this->rollBack();
            if ($mayRetry && ($error->getCode() === '23000' || $error->getCode() === '40001')) {
                return $this->claimInTransaction($playerId, $definition, false);
            }
            throw $error;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    private function claimPayload(
        string $playerId,
        string $achievementId,
        int $coinsEarned,
        bool $duplicate,
    ): array {
        $payload = $this->payload($playerId);
        $claimedAchievement = null;
        foreach ($payload['achievements'] as $achievement) {
            if (hash_equals($achievement['id'], $achievementId)) {
                $claimedAchievement = $achievement;
                break;
            }
        }

        return [
            ...$payload,
            'achievement' => $claimedAchievement,
            'coinsEarned' => $coinsEarned,
            'duplicate' => $duplicate,
        ];
    }

    private function syncHistoricalEligibility(
        string $playerId,
        int $totalCoinsCollected,
        int $economyGeneration,
    ): void
    {
        $runs = $this->database->prepare(
            'SELECT '
            . "COALESCE(MAX(mode = 'normal'), 0) AS completed_arcade, "
            . 'COALESCE(MAX(score > 100000), 0) AS scored_over_100k '
            . "FROM completed_runs WHERE player_id = :player_id "
            . "AND economy_generation = :economy_generation "
            . "AND verification_status = 'verified' AND coin_status = 'eligible'"
        );
        $runs->execute([
            'player_id' => $playerId,
            'economy_generation' => $economyGeneration,
        ]);
        $runEligibility = $runs->fetch() ?: [];

        if ((bool) ($runEligibility['completed_arcade'] ?? false)) {
            $this->unlock($playerId, AchievementCatalog::COMPLETE_ARCADE);
        }
        if ((bool) ($runEligibility['scored_over_100k'] ?? false)) {
            $this->unlock($playerId, AchievementCatalog::SCORE_OVER_100K);
        }

        $godlike = $this->database->prepare(
            'SELECT 1 FROM leaderboard_entries entry '
            . 'INNER JOIN completed_runs run ON run.leaderboard_entry_id = entry.id '
            . "WHERE entry.player_id = :player_id AND entry.verification_status = 'verified' "
            . "AND run.economy_generation = :economy_generation "
            . 'AND entry.godlike_count > 0 LIMIT 1'
        );
        $godlike->execute([
            'player_id' => $playerId,
            'economy_generation' => $economyGeneration,
        ]);
        if ($godlike->fetchColumn() !== false) {
            $this->unlock($playerId, AchievementCatalog::GODLIKE_SPEED);
        }

        if ($totalCoinsCollected >= 5) {
            $this->unlock($playerId, AchievementCatalog::COLLECT_FIVE_COINS);
        }

        if ($this->hasPetOwnershipTable()) {
            $petPurchase = $this->database->prepare(
                "SELECT 1 FROM player_pets WHERE player_id = :player_id "
                . "AND acquisition_source = 'purchase' LIMIT 1"
            );
            $petPurchase->execute(['player_id' => $playerId]);
            if ($petPurchase->fetchColumn() !== false) {
                $this->unlock($playerId, AchievementCatalog::BUY_A_PET);
            }
        }
    }

    private function unlock(string $playerId, string $achievementId): void
    {
        $definition = AchievementCatalog::require($achievementId);
        $statement = $this->database->prepare(
            'INSERT IGNORE INTO player_achievements '
            . '(player_id, achievement_key, reward_coins) '
            . 'VALUES (:player_id, :achievement_key, :reward_coins)'
        );
        $statement->execute([
            'player_id' => $playerId,
            'achievement_key' => $definition['id'],
            'reward_coins' => $definition['rewardCoins'],
        ]);
    }

    private function hasPetOwnershipTable(): bool
    {
        if ($this->petOwnershipAvailable !== null) {
            return $this->petOwnershipAvailable;
        }

        $statement = $this->database->query(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_pets'"
        );
        $this->petOwnershipAvailable = (int) $statement->fetchColumn() > 0;
        return $this->petOwnershipAvailable;
    }

    private function lockPlayer(string $playerId): array
    {
        $statement = $this->database->prepare(
            'SELECT coins, coin_debt, total_coins_collected, total_play_ms, economy_generation '
            . 'FROM players WHERE id = :player_id FOR UPDATE'
        );
        $statement->execute(['player_id' => $playerId]);
        $player = $statement->fetch();
        if (!is_array($player)) {
            throw new ApiException(401, 'Sign in with Google to continue.');
        }
        return $player;
    }

    private function insertRewardLedger(
        string $playerId,
        int $economyGeneration,
        string $achievementId,
        int $rewardCoins,
        int $coinBalance,
        int $coinDebt,
        int $totalPlayMs,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO coin_ledger '
            . '(event_id, event_key, player_id, economy_generation, event_type, play_ms_delta, coin_delta, '
            . 'coin_balance_after, coin_debt_after, total_play_ms_after, coin_status, actor, reason) '
            . 'VALUES (:event_id, :event_key, :player_id, :economy_generation, \'achievement_reward\', 0, :coin_delta, '
            . ':coin_balance_after, :coin_debt_after, :total_play_ms_after, \'eligible\', '
            . '\'achievement-service\', :reason)'
        );
        $statement->execute([
            'event_id' => Uuid::v4(),
            'event_key' => 'achievement:' . $playerId . ':' . $achievementId . ':g' . $economyGeneration,
            'player_id' => $playerId,
            'economy_generation' => $economyGeneration,
            'coin_delta' => $rewardCoins,
            'coin_balance_after' => $coinBalance,
            'coin_debt_after' => $coinDebt,
            'total_play_ms_after' => $totalPlayMs,
            'reason' => 'Claimed achievement ' . $achievementId . '.',
        ]);
    }

    private function lockAchievement(string $playerId, string $achievementId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT reward_coins, claimed_at FROM player_achievements '
            . 'WHERE player_id = :player_id AND achievement_key = :achievement_key FOR UPDATE'
        );
        $statement->execute([
            'player_id' => $playerId,
            'achievement_key' => $achievementId,
        ]);
        $achievement = $statement->fetch();
        return is_array($achievement) ? $achievement : null;
    }

    /** @return array<string, array{reward_coins: int|string, unlocked_at: string, claimed_at: ?string}> */
    private function achievementRows(string $playerId): array
    {
        $statement = $this->database->prepare(
            'SELECT achievement_key, reward_coins, unlocked_at, claimed_at '
            . 'FROM player_achievements WHERE player_id = :player_id'
        );
        $statement->execute(['player_id' => $playerId]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['achievement_key']] = $row;
        }
        return $rows;
    }

    private function formatPayload(?string $playerId, array $rows, int $coinBalance): array
    {
        $achievements = [];
        $claimedCount = 0;
        foreach (AchievementCatalog::all() as $definition) {
            $row = $rows[$definition['id']] ?? null;
            $claimed = is_array($row) && $row['claimed_at'] !== null;
            $unlocked = is_array($row);
            if ($claimed) {
                $claimedCount++;
            }
            $achievements[] = [
                ...$definition,
                'state' => $claimed ? 'claimed' : ($unlocked ? 'claimable' : 'locked'),
                'unlockedAt' => $unlocked ? self::isoDate((string) $row['unlocked_at']) : null,
                'claimedAt' => $claimed ? self::isoDate((string) $row['claimed_at']) : null,
            ];
        }

        return [
            'authenticated' => $playerId !== null,
            'achievements' => $achievements,
            'claimedCount' => $claimedCount,
            'totalCount' => count($achievements),
            'coinBalance' => $coinBalance,
        ];
    }

    private static function isoDate(string $value): string
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    private function rollBack(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }
}
