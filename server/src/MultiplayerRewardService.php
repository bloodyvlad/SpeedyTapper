<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;

/** Trusted room aggregates become immutable, generation-bound earned credits. */
final class MultiplayerRewardService
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function creditInCurrentTransaction(array $payload, string $digest, array $participant): array
    {
        if (!$this->database->inTransaction()) throw new \LogicException('Rewards must share result settlement.');
        $playerId = $participant['playerID'];
        $existing = $this->database->prepare('SELECT payload_hash FROM multiplayer_v2_reward_receipts '
            . 'WHERE match_id = :match AND player_id = :player');
        $existing->execute(['match' => $payload['matchID'], 'player' => $playerId]);
        if ($existing->fetchColumn() !== false) {
            // Shared alpha history can be removed by a peer's account deletion.
            // Its surviving owner's receipt must never be credited a second time.
            throw new ApiException(409, 'This multiplayer reward already has immutable evidence.');
        }
        $wallets = new CoinWalletRepository($this->database);
        $player = $wallets->lock($playerId);
        $generation = (int) $player['economy_generation'];
        $total = $this->totalAliveMs($playerId, $generation);
        $coinStatus = !$payload['rankingEligible'] ? 'ineligible'
            : ($participant['economyGeneration'] === null ? 'missing_generation'
                : ($participant['economyGeneration'] !== $generation ? 'stale_generation' : 'eligible'));
        $credited = $coinStatus === 'eligible' ? $participant['eligibleAliveMs'] : 0;
        $progress = MultiplayerRewardProgression::accrue($total, $credited);
        $eventId = null;
        if ($coinStatus === 'eligible') {
            $eventId = Uuid::v4();
            $eventKey = 'mp2:' . $payload['matchID'] . ':' . $playerId;
            $wallet = $wallets->creditEarned($playerId, $progress->coinsEarned, $player);
            if ($wallet['refundDebtPaid'] > 0) {
                $wallets->allocateRefundDebtPayment($playerId, 'earned_credit', $eventKey, null,
                    $wallet['refundDebtPaid'], $generation);
            }
            $ledger = $this->database->prepare(
                'INSERT INTO coin_ledger (event_id,event_key,player_id,economy_generation,run_id,event_type, '
                . 'play_ms_delta,coin_delta,remainder_before_ms,remainder_after_ms,earned_delta,purchased_delta, '
                . 'coin_balance_after,earned_balance_after,purchased_balance_after,coin_debt_after, '
                . 'earned_debt_after,refund_debt_after,total_play_ms_after,coin_status,actor,reason) VALUES '
                . "(:id,:key,:player,:generation,NULL,'multiplayer_credit',:time,:coins,:before,:after,:earned,0, "
                . ':balance,:earned_balance,:purchased_balance,:debt,:earned_debt,:refund_debt,:arcade_time, '
                . "'eligible','realtime-server','Revision-3 alive connected multiplayer time; two coins per minute.')"
            );
            $ledger->execute([
                'id' => $eventId, 'key' => $eventKey, 'player' => $playerId, 'generation' => $generation,
                'time' => $credited, 'coins' => $progress->coinsEarned, 'earned' => $progress->coinsEarned,
                'before' => $total % 60_000, 'after' => $progress->remainderMs,
                'balance' => $wallet['coins'], 'earned_balance' => $wallet['earnedCoins'],
                'purchased_balance' => $wallet['purchasedCoins'], 'debt' => $wallet['debt'],
                'earned_debt' => $wallet['earnedDebt'], 'refund_debt' => $wallet['refundDebt'],
                // Existing ledger compatibility column remains Arcade-only.
                'arcade_time' => $player['total_play_ms'],
            ]);
        }
        $receipt = $this->database->prepare(
            'INSERT INTO multiplayer_v2_reward_receipts (match_id,player_id,payload_hash,result_revision, '
            . 'gameplay_revision,reward_policy,match_kind,completion_reason,reported_economy_generation, '
            . 'economy_generation,eligible_alive_ms,credited_alive_ms,coins_awarded,remainder_before_ms, '
            . 'remainder_after_ms,total_alive_ms,coin_status,ledger_event_id) VALUES '
            . '(:match,:player,:hash,2,3,:policy,:kind,:completion,:reported_generation,:generation, '
            . ':eligible,:credited,:coins,:before,:after,:total,:status,:ledger)'
        );
        $receipt->execute([
            'match' => $payload['matchID'], 'player' => $playerId, 'hash' => $digest,
            'policy' => $payload['rewardPolicy'], 'kind' => $payload['matchKind'],
            'completion' => $payload['completionReason'], 'reported_generation' => $participant['economyGeneration'],
            'generation' => $generation, 'eligible' => $participant['eligibleAliveMs'],
            'credited' => $credited, 'coins' => $progress->coinsEarned, 'before' => $total % 60_000,
            'after' => $progress->remainderMs, 'total' => $progress->totalAliveMs, 'status' => $coinStatus,
            'ledger' => $eventId,
        ]);
        return ['playerID' => $playerId, 'creditedAliveMs' => $credited, 'coinsEarned' => $progress->coinsEarned,
            'remainderMs' => $progress->remainderMs, 'totalAliveMs' => $progress->totalAliveMs, 'coinStatus' => $coinStatus];
    }

    public function receipts(string $matchId): array
    {
        $query = $this->database->prepare('SELECT player_id,credited_alive_ms,coins_awarded, '
            . 'remainder_after_ms,total_alive_ms,coin_status FROM multiplayer_v2_reward_receipts '
            . 'WHERE match_id = :match ORDER BY player_id');
        $query->execute(['match' => $matchId]);
        return array_map(static fn (array $row): array => [
            'playerID' => $row['player_id'], 'creditedAliveMs' => (int) $row['credited_alive_ms'],
            'coinsEarned' => (int) $row['coins_awarded'], 'remainderMs' => (int) $row['remainder_after_ms'],
            'totalAliveMs' => (int) $row['total_alive_ms'], 'coinStatus' => $row['coin_status'],
        ], $query->fetchAll());
    }

    private function totalAliveMs(string $playerId, int $generation): int
    {
        $query = $this->database->prepare('SELECT COALESCE(SUM(play_ms_delta),0) FROM coin_ledger '
            . 'WHERE player_id = :player AND economy_generation = :generation '
            . "AND event_type = 'multiplayer_credit' AND coin_status = 'eligible'");
        $query->execute(['player' => $playerId, 'generation' => $generation]);
        return (int) $query->fetchColumn();
    }
}
