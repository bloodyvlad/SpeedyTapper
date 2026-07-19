<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;

final class CoinWalletRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array<string, int> */
    public function lock(string $playerId): array
    {
        if (!$this->database->inTransaction()) {
            throw new \LogicException('A wallet lock requires an active database transaction.');
        }
        $statement = $this->database->prepare(
            'SELECT earned_coins, purchased_coins, earned_coin_debt, refund_coin_debt, '
            . 'coins, coin_debt, total_play_ms, total_coins_collected, coin_time_remainder_ms, '
            . 'economy_generation FROM players WHERE id = :player_id FOR UPDATE'
        );
        $statement->execute(['player_id' => $playerId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(401, 'Sign in with Google to continue.');
        }
        return array_map('intval', $row);
    }

    /** @return array<string, int> */
    public function creditEarned(string $playerId, int $grossCredit, ?array $lockedPlayer = null): array
    {
        $player = $lockedPlayer ?? $this->lock($playerId);
        $wallet = CoinEconomy::applyEarnedCredit(
            $player['earned_coins'],
            $player['purchased_coins'],
            $player['earned_coin_debt'],
            $player['refund_coin_debt'],
            $grossCredit,
        );
        return [
            ...$this->persist($playerId, $wallet),
            'earnedDebtPaid' => $wallet['earnedDebtPaid'],
            'refundDebtPaid' => $wallet['refundDebtPaid'],
        ];
    }

    /** @return array<string, int> */
    public function creditPurchased(string $playerId, int $grossCredit, ?array $lockedPlayer = null): array
    {
        $player = $lockedPlayer ?? $this->lock($playerId);
        $wallet = CoinEconomy::applyPurchasedCredit(
            $player['earned_coins'],
            $player['purchased_coins'],
            $player['earned_coin_debt'],
            $player['refund_coin_debt'],
            $grossCredit,
        );
        return [
            ...$this->persist($playerId, $wallet),
            'refundDebtPaid' => $wallet['refundDebtPaid'],
        ];
    }

    /**
     * Atomically spend a catalog price and preserve the exact earned/purchased provenance.
     *
     * @return array{eventId: string, earnedSpent: int, purchasedSpent: int, coins: int, debt: int, earnedCoins: int, purchasedCoins: int, earnedDebt: int, refundDebt: int}
     */
    public function spend(
        string $playerId,
        int $amount,
        string $eventType,
        string $eventKey,
        string $purpose,
        string $actor,
        string $reason,
        ?array $lockedPlayer = null,
    ): array {
        $player = $lockedPlayer ?? $this->lock($playerId);
        if ($player['refund_coin_debt'] > 0) {
            throw new ApiException(409, 'Purchases are unavailable while an App Store refund balance is outstanding.');
        }
        try {
            $spent = CoinEconomy::spendEarnedFirst(
                $player['earned_coins'],
                $player['purchased_coins'],
                $player['earned_coin_debt'],
                $player['refund_coin_debt'],
                $amount,
            );
        } catch (\InvalidArgumentException) {
            $missing = max(0, $amount - CoinEconomy::totalCoins(
                $player['earned_coins'],
                $player['purchased_coins'],
            ));
            throw new ApiException(409, sprintf(
                'You need %d more %s to complete this purchase.',
                $missing,
                $missing === 1 ? 'coin' : 'coins',
            ));
        }

        $wallet = $this->persist($playerId, $spent);
        $eventId = Uuid::v4();
        $this->insertLedger(
            $eventId,
            $eventKey,
            $playerId,
            $player['economy_generation'],
            $eventType,
            -$amount,
            -$spent['earnedSpent'],
            -$spent['purchasedSpent'],
            $wallet,
            $player['total_play_ms'],
            $actor,
            $reason,
        );
        if ($spent['earnedSpent'] > 0) {
            $this->insertAllocation(
                $eventId,
                $playerId,
                'earned',
                null,
                $spent['earnedSpent'],
                $purpose,
                $eventKey,
            );
        }
        if ($spent['purchasedSpent'] > 0) {
            $this->allocatePurchasedLots(
                $eventId,
                $playerId,
                $spent['purchasedSpent'],
                $purpose,
                $eventKey,
            );
        }

        return [
            'eventId' => $eventId,
            'earnedSpent' => $spent['earnedSpent'],
            'purchasedSpent' => $spent['purchasedSpent'],
            ...$wallet,
        ];
    }

    /** Allocate a new paid credit against exact outstanding refund transactions. */
    public function allocateRefundDebtPayment(
        string $playerId,
        string $sourceType,
        string $sourceReference,
        ?string $sourceTransactionId,
        int $amount,
        ?int $sourceEconomyGeneration = null,
    ): void
    {
        if (!in_array($sourceType, ['earned_credit', 'storekit_purchase'], true)
            || $sourceReference === ''
            || (($sourceType === 'storekit_purchase') !== ($sourceTransactionId !== null))
        ) {
            throw new \InvalidArgumentException('Refund-debt settlement source is invalid.');
        }
        $remaining = $amount;
        if ($remaining === 0) return;
        if ($sourceType === 'earned_credit' && $sourceEconomyGeneration === null) {
            throw new \InvalidArgumentException('Earned refund-debt settlements require an economy generation.');
        }
        $refunds = $this->database->prepare(
            "SELECT transaction_id, refund_debt_outstanding, base_refund_debt_outstanding "
            . 'FROM storekit_transactions '
            . 'WHERE player_id = :player_id AND refund_debt_outstanding > 0 '
            . 'ORDER BY updated_at, transaction_id FOR UPDATE'
        );
        $refunds->execute(['player_id' => $playerId]);
        foreach ($refunds->fetchAll() as $refund) {
            if ($remaining === 0) break;
            $baseTake = min($remaining, (int) $refund['base_refund_debt_outstanding']);
            if ($baseTake > 0) {
                $this->decrementRefundDebtComponent(
                    (string) $refund['transaction_id'],
                    null,
                    $baseTake,
                );
                $this->insertRefundDebtAllocation(
                    $playerId,
                    $sourceType,
                    $sourceReference,
                    $sourceTransactionId,
                    $sourceEconomyGeneration,
                    (string) $refund['transaction_id'],
                    null,
                    $baseTake,
                );
                $remaining -= $baseTake;
            }
            if ($remaining === 0) break;

            $components = $this->database->prepare(
                'SELECT debt_id, amount, settled_amount FROM storekit_cosmetic_restore_debts '
                . 'WHERE player_id = :player_id AND refund_transaction_id = :transaction_id '
                . 'AND released_at IS NULL AND settled_amount < amount '
                . 'ORDER BY created_at, debt_id FOR UPDATE'
            );
            $components->execute([
                'player_id' => $playerId,
                'transaction_id' => $refund['transaction_id'],
            ]);
            foreach ($components->fetchAll() as $component) {
                if ($remaining === 0) break;
                $take = min(
                    $remaining,
                    (int) $component['amount'] - (int) $component['settled_amount'],
                );
                $this->decrementRefundDebtComponent(
                    (string) $refund['transaction_id'],
                    (string) $component['debt_id'],
                    $take,
                );
                $this->insertRefundDebtAllocation(
                    $playerId,
                    $sourceType,
                    $sourceReference,
                    $sourceTransactionId,
                    $sourceEconomyGeneration,
                    (string) $refund['transaction_id'],
                    (string) $component['debt_id'],
                    $take,
                );
                $remaining -= $take;
            }
        }
        if ($remaining !== 0) {
            throw new \RuntimeException('Aggregate refund debt has no matching transaction provenance.');
        }
    }

    /** Remove only the refunded transaction lot's remaining value and expose its exact shortfall. */
    public function refundLot(string $playerId, string $transactionId, int $transitionVersion): array
    {
        $player = $this->lock($playerId);
        $lotStatement = $this->database->prepare(
            'SELECT gross_coins, available_coins, spent_coins, refund_debt_settled_coins, status '
            . 'FROM purchased_coin_lots WHERE transaction_id = :transaction_id '
            . 'AND player_id = :player_id FOR UPDATE'
        );
        $lotStatement->execute(['transaction_id' => $transactionId, 'player_id' => $playerId]);
        $lot = $lotStatement->fetch();
        if (!is_array($lot)) {
            throw new \RuntimeException('The refunded purchased-coin lot was not found.');
        }
        if ($lot['status'] === 'refunded') {
            return CoinEconomy::summary(
                $player['earned_coins'],
                $player['purchased_coins'],
                $player['earned_coin_debt'],
                $player['refund_coin_debt'],
            );
        }

        $available = (int) $lot['available_coins'];
        $shortfall = (int) $lot['spent_coins'] + (int) $lot['refund_debt_settled_coins'];
        if ($available > $player['purchased_coins']) {
            throw new \RuntimeException('The refunded lot exceeds the purchased wallet balance.');
        }
        $wallet = $this->persist($playerId, [
            'earnedCoins' => $player['earned_coins'],
            'purchasedCoins' => $player['purchased_coins'] - $available,
            'earnedDebt' => $player['earned_coin_debt'],
            'refundDebt' => $player['refund_coin_debt'] + $shortfall,
        ]);
        $updateLot = $this->database->prepare(
            "UPDATE purchased_coin_lots SET available_coins = 0, reversed_coins = reversed_coins + :available_credit, "
            . "status = 'refunded', updated_at = UTC_TIMESTAMP(3) WHERE transaction_id = :transaction_id"
        );
        $updateLot->execute(['available_credit' => $available, 'transaction_id' => $transactionId]);
        if ($updateLot->rowCount() !== 1) {
            throw new \RuntimeException('The purchased lot changed during refund reversal.');
        }
        $updateTransaction = $this->database->prepare(
            'UPDATE storekit_transactions SET refund_debt_created = refund_debt_created + :created, '
            . 'refund_debt_outstanding = refund_debt_outstanding + :outstanding, '
            . 'base_refund_debt_outstanding = base_refund_debt_outstanding + :base_outstanding '
            . 'WHERE transaction_id = :transaction_id'
        );
        $updateTransaction->execute([
            'created' => $shortfall,
            'outstanding' => $shortfall,
            'base_outstanding' => $shortfall,
            'transaction_id' => $transactionId,
        ]);

        $eventId = Uuid::v4();
        $this->insertLedger(
            $eventId,
            'storekit-refund:' . hash('sha256', $transactionId) . ':v' . $transitionVersion,
            $playerId,
            $player['economy_generation'],
            'storekit_refund',
            -((int) $lot['gross_coins']),
            0,
            -((int) $lot['gross_coins']),
            $wallet,
            $player['total_play_ms'],
            'app-store-notifications',
            'Verified App Store refund.',
        );
        return ['eventId' => $eventId, 'shortfall' => $shortfall, ...$wallet];
    }

    /** Restore a refunded lot and recursively unwind every credit that paid its debt. */
    public function restoreRefundedLot(
        string $playerId,
        string $transactionId,
        int $transitionVersion,
    ): array {
        $player = $this->lock($playerId);
        $lotStatement = $this->database->prepare(
            'SELECT gross_coins, reversed_coins, status FROM purchased_coin_lots '
            . 'WHERE transaction_id = :transaction_id AND player_id = :player_id FOR UPDATE'
        );
        $lotStatement->execute(['transaction_id' => $transactionId, 'player_id' => $playerId]);
        $lot = $lotStatement->fetch();
        if (!is_array($lot)) {
            throw new \RuntimeException('The refunded StoreKit lot was not found.');
        }
        if ($lot['status'] !== 'refunded') {
            return CoinEconomy::summary(
                $player['earned_coins'], $player['purchased_coins'],
                $player['earned_coin_debt'], $player['refund_coin_debt'],
            );
        }

        $changes = ['earned' => 0, 'purchased' => 0, 'debtRemoved' => 0];
        $visited = [];
        $this->cancelBaseRefundDebt(
            $playerId,
            $transactionId,
            PHP_INT_MAX,
            $player['economy_generation'],
            $changes,
            $visited,
        );

        $reversed = (int) $lot['reversed_coins'];
        $baseWallet = $this->walletAfterExactRestoration($player, $changes);
        $refundDebtPaid = min($baseWallet['refundDebt'], $reversed);
        $availableRestored = $reversed - $refundDebtPaid;
        $restoreLot = $this->database->prepare(
            'UPDATE purchased_coin_lots SET available_coins = available_coins + :available_credit, '
            . 'refund_debt_settled_coins = refund_debt_settled_coins + :settled_credit, '
            . "reversed_coins = 0, status = 'reinstated', updated_at = UTC_TIMESTAMP(3) "
            . "WHERE transaction_id = :transaction_id AND status = 'refunded'"
        );
        $restoreLot->execute([
            'available_credit' => $availableRestored,
            'settled_credit' => $refundDebtPaid,
            'transaction_id' => $transactionId,
        ]);
        if ($restoreLot->rowCount() !== 1) {
            throw new \RuntimeException('The refunded lot could not be reinstated.');
        }
        $wallet = $this->persist($playerId, [
            'earnedCoins' => $baseWallet['earnedCoins'],
            'purchasedCoins' => $baseWallet['purchasedCoins'] + $availableRestored,
            'earnedDebt' => $baseWallet['earnedDebt'],
            'refundDebt' => $baseWallet['refundDebt'] - $refundDebtPaid,
        ]);
        if ($refundDebtPaid > 0) {
            $this->allocateRefundDebtPayment(
                $playerId,
                'storekit_purchase',
                $transactionId . ':refund-reversal:v' . $transitionVersion,
                $transactionId,
                $refundDebtPaid,
            );
        }
        $this->insertLedger(
            Uuid::v4(),
            'storekit-refund-reversal:' . hash('sha256', $transactionId) . ':v' . $transitionVersion,
            $playerId,
            $player['economy_generation'],
            'storekit_refund_reversal',
            (int) $lot['gross_coins'],
            0,
            (int) $lot['gross_coins'],
            $wallet,
            $player['total_play_ms'],
            'app-store-notifications',
            'Verified App Store refund reversal.',
        );
        return [
            'releasedCoins' => $changes['earned'] + $changes['purchased'] + $availableRestored,
            'debtRemoved' => $changes['debtRemoved'],
            'refundDebtPaid' => $refundDebtPaid,
            ...$wallet,
        ];
    }

    /** Reverse one cosmetic debit, excluding value from the transaction being refunded. */
    public function reverseCosmeticSpend(
        string $playerId,
        string $purchaseEventId,
        string $refundedTransactionId,
    ): array {
        $player = $this->lock($playerId);
        $allocations = $this->database->prepare(
            'SELECT allocation.allocation_id, allocation.source, allocation.lot_transaction_id, '
            . 'allocation.amount, ledger.economy_generation FROM coin_spend_allocations allocation '
            . 'LEFT JOIN coin_ledger ledger ON ledger.event_id = allocation.spend_event_id '
            . 'WHERE allocation.spend_event_id = :spend_event_id AND allocation.player_id = :player_id '
            . 'AND allocation.released_at IS NULL '
            . 'ORDER BY allocation.created_at, allocation.allocation_id FOR UPDATE'
        );
        $allocations->execute(['spend_event_id' => $purchaseEventId, 'player_id' => $playerId]);
        $rows = $allocations->fetchAll();
        if ($rows === []) {
            throw new \RuntimeException('The refunded cosmetic has no active spend allocations.');
        }

        $earnedRestore = 0;
        $purchasedRestore = 0;
        $refundedContribution = 0;
        foreach ($rows as $allocation) {
            $amount = (int) $allocation['amount'];
            if ($allocation['source'] === 'earned') {
                if ((int) $allocation['economy_generation'] === $player['economy_generation']) {
                    $earnedRestore += $amount;
                }
            } elseif (hash_equals((string) $allocation['lot_transaction_id'], $refundedTransactionId)) {
                $refundedContribution += $amount;
                $moveRefunded = $this->database->prepare(
                    'UPDATE purchased_coin_lots SET spent_coins = spent_coins - :spent_debit, '
                    . 'reversed_coins = reversed_coins + :reversed_credit '
                    . 'WHERE transaction_id = :transaction_id AND spent_coins >= :spent_guard'
                );
                $moveRefunded->execute([
                    'spent_debit' => $amount,
                    'reversed_credit' => $amount,
                    'spent_guard' => $amount,
                    'transaction_id' => $refundedTransactionId,
                ]);
                if ($moveRefunded->rowCount() !== 1) {
                    throw new \RuntimeException('Refunded cosmetic allocation drifted from its paid lot.');
                }
            } else {
                $purchasedRestore += $amount;
                $restorePurchased = $this->database->prepare(
                    'UPDATE purchased_coin_lots SET spent_coins = spent_coins - :spent_debit, '
                    . 'available_coins = available_coins + :available_credit '
                    . 'WHERE transaction_id = :transaction_id AND spent_coins >= :spent_guard'
                );
                $restorePurchased->execute([
                    'spent_debit' => $amount,
                    'available_credit' => $amount,
                    'spent_guard' => $amount,
                    'transaction_id' => $allocation['lot_transaction_id'],
                ]);
                if ($restorePurchased->rowCount() !== 1) {
                    throw new \RuntimeException('Cosmetic allocation drifted from its paid lot.');
                }
            }
            $releaseAllocation = $this->database->prepare(
                'UPDATE coin_spend_allocations SET released_at = UTC_TIMESTAMP(3) '
                . 'WHERE allocation_id = :allocation_id AND released_at IS NULL'
            );
            $releaseAllocation->execute(['allocation_id' => $allocation['allocation_id']]);
            if ($releaseAllocation->rowCount() !== 1) {
                throw new \RuntimeException('Cosmetic spend allocation changed during refund.');
            }
        }

        // This is an exact reversal of an unrelated earned allocation. It may
        // repay earned moderation debt, but it must not be consumed by a
        // separate App Store refund debt.
        $earnedWallet = CoinEconomy::applyEarnedCredit(
            $player['earned_coins'],
            $player['purchased_coins'] + $purchasedRestore,
            $player['earned_coin_debt'],
            0,
            $earnedRestore,
        );
        $wallet = $this->persist($playerId, [
            'earnedCoins' => $earnedWallet['earnedCoins'],
            'purchasedCoins' => $earnedWallet['purchasedCoins'],
            'earnedDebt' => $earnedWallet['earnedDebt'],
            'refundDebt' => $player['refund_coin_debt'],
        ]);
        $eventId = Uuid::v4();
        $this->insertLedger(
            $eventId,
            'storekit-cosmetic-refund:' . $purchaseEventId,
            $playerId,
            $player['economy_generation'],
            'storekit_cosmetic_refund',
            $earnedRestore + $purchasedRestore,
            $earnedRestore,
            $purchasedRestore,
            $wallet,
            $player['total_play_ms'],
            'app-store-notifications',
            'Restored non-refunded allocations from a refunded cosmetic.',
        );
        return [
            'eventId' => $eventId,
            'earnedRestored' => $earnedRestore,
            'purchasedRestored' => $purchasedRestore,
            'refundedContribution' => $refundedContribution,
            ...$wallet,
        ];
    }

    /** Reapply a restored cosmetic debit; any unavailable amount becomes refund debt. */
    public function forceCosmeticRestoreSpend(
        string $playerId,
        int $amount,
        string $itemType,
        string $itemId,
        string $refundTransactionId,
    ): array {
        $player = $this->lock($playerId);
        $earnedSpent = min($amount, $player['earned_coins']);
        $afterEarned = $amount - $earnedSpent;
        $purchasedSpent = min($afterEarned, $player['purchased_coins']);
        $shortfall = $afterEarned - $purchasedSpent;
        $wallet = $this->persist($playerId, [
            'earnedCoins' => $player['earned_coins'] - $earnedSpent,
            'purchasedCoins' => $player['purchased_coins'] - $purchasedSpent,
            'earnedDebt' => $player['earned_coin_debt'],
            'refundDebt' => $player['refund_coin_debt'] + $shortfall,
        ]);
        if ($shortfall > 0) {
            $debt = $this->database->prepare(
                'UPDATE storekit_transactions SET refund_debt_created = '
                . 'refund_debt_created + :created, refund_debt_outstanding = '
                . 'refund_debt_outstanding + :outstanding WHERE transaction_id = :transaction_id'
            );
            $debt->execute([
                'created' => $shortfall,
                'outstanding' => $shortfall,
                'transaction_id' => $refundTransactionId,
            ]);
            if ($debt->rowCount() !== 1) {
                throw new \RuntimeException('Refund-reversal shortfall lost its transaction provenance.');
            }
        }
        $eventId = Uuid::v4();
        $eventKey = 'storekit-cosmetic-restore:' . hash(
            'sha256',
            $refundTransactionId . "\0" . $itemType . "\0" . $itemId . "\0" . Uuid::v4(),
        );
        $this->insertLedger(
            $eventId,
            $eventKey,
            $playerId,
            $player['economy_generation'],
            'storekit_cosmetic_restore',
            -$amount,
            -$earnedSpent,
            -$purchasedSpent,
            $wallet,
            $player['total_play_ms'],
            'app-store-notifications',
            'Restored a cosmetic after App Store refund reversal.',
        );
        if ($earnedSpent > 0) {
            $this->insertAllocation(
                $eventId, $playerId, 'earned', null, $earnedSpent,
                $itemType . '_purchase', $eventKey,
            );
        }
        if ($purchasedSpent > 0) {
            $this->allocatePurchasedLots(
                $eventId, $playerId, $purchasedSpent,
                $itemType . '_purchase', $eventKey,
            );
        }
        if ($shortfall > 0) {
            $this->database->prepare(
                'INSERT INTO storekit_cosmetic_restore_debts '
                . '(debt_id, refund_transaction_id, player_id, purchase_event_id, '
                . 'item_type, item_id, amount) VALUES '
                . '(:debt_id, :refund_transaction_id, :player_id, :purchase_event_id, '
                . ':item_type, :item_id, :amount)'
            )->execute([
                'debt_id' => Uuid::v4(),
                'refund_transaction_id' => $refundTransactionId,
                'player_id' => $playerId,
                'purchase_event_id' => $eventId,
                'item_type' => $itemType,
                'item_id' => $itemId,
                'amount' => $shortfall,
            ]);
        }
        return ['eventId' => $eventId, 'shortfall' => $shortfall, ...$wallet];
    }

    /** Cancel debt created when this transaction restored a cosmetic. */
    public function cancelCosmeticRestoreDebt(
        string $playerId,
        string $refundTransactionId,
        string $purchaseEventId,
    ): array {
        $player = $this->lock($playerId);
        $statement = $this->database->prepare(
            'SELECT debt_id, amount, settled_amount FROM storekit_cosmetic_restore_debts '
            . 'WHERE player_id = :player_id AND refund_transaction_id = :transaction_id '
            . 'AND purchase_event_id = :purchase_event_id AND released_at IS NULL FOR UPDATE'
        );
        $statement->execute([
            'player_id' => $playerId,
            'transaction_id' => $refundTransactionId,
            'purchase_event_id' => $purchaseEventId,
        ]);
        $rows = $statement->fetchAll();
        if ($rows === []) return ['releasedDebt' => 0];

        $changes = ['earned' => 0, 'purchased' => 0, 'debtRemoved' => 0];
        $visited = [];
        $releasedDebt = 0;
        foreach ($rows as $row) {
            $outstanding = (int) $row['amount'] - (int) $row['settled_amount'];
            if ($outstanding > 0) {
                $transaction = $this->database->prepare(
                    'UPDATE storekit_transactions SET refund_debt_outstanding = '
                    . 'refund_debt_outstanding - :decrement WHERE transaction_id = :transaction_id '
                    . 'AND refund_debt_outstanding >= :minimum'
                );
                $transaction->execute([
                    'decrement' => $outstanding,
                    'minimum' => $outstanding,
                    'transaction_id' => $refundTransactionId,
                ]);
                if ($transaction->rowCount() !== 1) {
                    throw new \RuntimeException('Cosmetic restore debt transaction provenance drifted.');
                }
                $changes['debtRemoved'] += $outstanding;
            }
            $releasedSettlements = $this->releaseDebtAllocations(
                $playerId,
                $refundTransactionId,
                (string) $row['debt_id'],
                PHP_INT_MAX,
                $player['economy_generation'],
                $changes,
                $visited,
            );
            if ($releasedSettlements !== (int) $row['settled_amount']) {
                throw new \RuntimeException('Cosmetic restore debt settlements do not reconcile.');
            }
            $release = $this->database->prepare(
                'UPDATE storekit_cosmetic_restore_debts SET settled_amount = 0, '
                . 'released_at = UTC_TIMESTAMP(3) WHERE debt_id = :debt_id AND released_at IS NULL'
            );
            $release->execute(['debt_id' => $row['debt_id']]);
            if ($release->rowCount() !== 1) {
                throw new \RuntimeException('Cosmetic restore debt changed during cancellation.');
            }
            $releasedDebt += (int) $row['amount'];
        }
        $wallet = $this->persist($playerId, $this->walletAfterExactRestoration($player, $changes));
        return ['releasedDebt' => $releasedDebt, ...$wallet];
    }

    /**
     * Cancel up to $limit of a transaction's base refund obligation. Unpaid
     * debt disappears; already-settled debt recursively restores its exact
     * earned or purchased source.
     *
     * @param array{earned:int,purchased:int,debtRemoved:int} $changes
     * @param array<string, true> $visited
     */
    private function cancelBaseRefundDebt(
        string $playerId,
        string $transactionId,
        int $limit,
        int $economyGeneration,
        array &$changes,
        array &$visited,
    ): int {
        if ($limit <= 0) return 0;
        if (isset($visited[$transactionId])) {
            throw new \RuntimeException('Refund-debt settlement cycle detected.');
        }
        $visited[$transactionId] = true;
        try {
            $transaction = $this->database->prepare(
                'SELECT base_refund_debt_outstanding FROM storekit_transactions '
                . 'WHERE transaction_id = :transaction_id FOR UPDATE'
            );
            $transaction->execute(['transaction_id' => $transactionId]);
            $baseOutstanding = $transaction->fetchColumn();
            if ($baseOutstanding === false) {
                throw new \RuntimeException('Refund-debt transaction was not found.');
            }
            $unpaid = min($limit, (int) $baseOutstanding);
            if ($unpaid > 0) {
                $update = $this->database->prepare(
                    'UPDATE storekit_transactions SET base_refund_debt_outstanding = '
                    . 'base_refund_debt_outstanding - :base_decrement, refund_debt_outstanding = '
                    . 'refund_debt_outstanding - :total_decrement WHERE transaction_id = :transaction_id '
                    . 'AND base_refund_debt_outstanding >= :base_minimum '
                    . 'AND refund_debt_outstanding >= :total_minimum'
                );
                $update->execute([
                    'base_decrement' => $unpaid,
                    'total_decrement' => $unpaid,
                    'base_minimum' => $unpaid,
                    'total_minimum' => $unpaid,
                    'transaction_id' => $transactionId,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('Refund debt changed while it was being cancelled.');
                }
                $changes['debtRemoved'] += $unpaid;
            }
            $remaining = $limit === PHP_INT_MAX ? PHP_INT_MAX : $limit - $unpaid;
            $released = $this->releaseDebtAllocations(
                $playerId,
                $transactionId,
                null,
                $remaining,
                $economyGeneration,
                $changes,
                $visited,
            );
            return $unpaid + $released;
        } finally {
            unset($visited[$transactionId]);
        }
    }

    /**
     * @param array{earned:int,purchased:int,debtRemoved:int} $changes
     * @param array<string, true> $visited
     */
    private function releaseDebtAllocations(
        string $playerId,
        string $refundTransactionId,
        ?string $cosmeticDebtId,
        int $limit,
        int $economyGeneration,
        array &$changes,
        array &$visited,
    ): int {
        if ($limit <= 0) return 0;
        $sql = 'SELECT allocation_id, source_type, source_economy_generation, '
            . 'source_purchase_transaction_id, amount, released_amount '
            . 'FROM storekit_refund_debt_allocations WHERE player_id = :player_id '
            . 'AND refund_transaction_id = :refund_transaction_id '
            . ($cosmeticDebtId === null
                ? 'AND cosmetic_restore_debt_id IS NULL '
                : 'AND cosmetic_restore_debt_id = :cosmetic_restore_debt_id ')
            . 'AND source_revoked_at IS NULL AND released_amount < amount '
            . 'ORDER BY created_at, allocation_id FOR UPDATE';
        $statement = $this->database->prepare($sql);
        $parameters = [
            'player_id' => $playerId,
            'refund_transaction_id' => $refundTransactionId,
        ];
        if ($cosmeticDebtId !== null) $parameters['cosmetic_restore_debt_id'] = $cosmeticDebtId;
        $statement->execute($parameters);
        $released = 0;
        foreach ($statement->fetchAll() as $allocation) {
            if ($released >= $limit) break;
            $active = (int) $allocation['amount'] - (int) $allocation['released_amount'];
            $remaining = $limit === PHP_INT_MAX ? $active : min($active, $limit - $released);
            if ($remaining <= 0) continue;

            if ($allocation['source_type'] === 'earned_credit') {
                if ((int) $allocation['source_economy_generation'] === $economyGeneration) {
                    $changes['earned'] += $remaining;
                }
            } else {
                $sourceTransactionId = (string) $allocation['source_purchase_transaction_id'];
                $source = $this->database->prepare(
                    'SELECT status, refund_debt_settled_coins FROM purchased_coin_lots '
                    . 'WHERE transaction_id = :transaction_id AND player_id = :player_id FOR UPDATE'
                );
                $source->execute([
                    'transaction_id' => $sourceTransactionId,
                    'player_id' => $playerId,
                ]);
                $sourceLot = $source->fetch();
                if (!is_array($sourceLot) || (int) $sourceLot['refund_debt_settled_coins'] < $remaining) {
                    throw new \RuntimeException('Refund-debt source lot no longer reconciles.');
                }
                if ($sourceLot['status'] === 'refunded') {
                    $cancelled = $this->cancelBaseRefundDebt(
                        $playerId,
                        $sourceTransactionId,
                        $remaining,
                        $economyGeneration,
                        $changes,
                        $visited,
                    );
                    if ($cancelled !== $remaining) {
                        throw new \RuntimeException('Chained refund debt could not be unwound exactly.');
                    }
                    $move = $this->database->prepare(
                        'UPDATE purchased_coin_lots SET refund_debt_settled_coins = '
                        . 'refund_debt_settled_coins - :settled_debit, reversed_coins = '
                        . 'reversed_coins + :reversed_credit WHERE transaction_id = :transaction_id '
                        . 'AND refund_debt_settled_coins >= :settled_minimum'
                    );
                } elseif (in_array($sourceLot['status'], ['active', 'reinstated'], true)) {
                    $move = $this->database->prepare(
                        'UPDATE purchased_coin_lots SET refund_debt_settled_coins = '
                        . 'refund_debt_settled_coins - :settled_debit, available_coins = '
                        . 'available_coins + :available_credit WHERE transaction_id = :transaction_id '
                        . 'AND refund_debt_settled_coins >= :settled_minimum'
                    );
                    $changes['purchased'] += $remaining;
                } else {
                    throw new \RuntimeException('Refund-debt source lot cannot be released.');
                }
                $creditParameter = $sourceLot['status'] === 'refunded'
                    ? 'reversed_credit'
                    : 'available_credit';
                $move->execute([
                    'settled_debit' => $remaining,
                    $creditParameter => $remaining,
                    'settled_minimum' => $remaining,
                    'transaction_id' => $sourceTransactionId,
                ]);
                if ($move->rowCount() !== 1) {
                    throw new \RuntimeException('Refund-debt source lot changed during release.');
                }
            }

            $release = $this->database->prepare(
                'UPDATE storekit_refund_debt_allocations SET released_amount = released_amount + :increment, '
                . 'released_at = CASE WHEN released_amount + :completion_increment = amount '
                . 'THEN UTC_TIMESTAMP(3) ELSE NULL END WHERE allocation_id = :allocation_id '
                . 'AND amount - released_amount >= :minimum'
            );
            $release->bindValue(':increment', $remaining, PDO::PARAM_INT);
            $release->bindValue(':completion_increment', $remaining, PDO::PARAM_INT);
            $release->bindValue(':minimum', $remaining, PDO::PARAM_INT);
            $release->bindValue(':allocation_id', $allocation['allocation_id']);
            $release->execute();
            if ($release->rowCount() !== 1) {
                throw new \RuntimeException('Refund-debt allocation changed during release.');
            }
            $released += $remaining;
        }
        return $released;
    }

    /** @param array<string,int> $player @param array{earned:int,purchased:int,debtRemoved:int} $changes */
    private function walletAfterExactRestoration(array $player, array $changes): array
    {
        if ($changes['debtRemoved'] > $player['refund_coin_debt']) {
            throw new \RuntimeException('Refund-debt provenance exceeds the aggregate debt.');
        }
        $earned = CoinEconomy::applyEarnedCredit(
            $player['earned_coins'],
            $player['purchased_coins'] + $changes['purchased'],
            $player['earned_coin_debt'],
            0,
            $changes['earned'],
        );
        return [
            'earnedCoins' => $earned['earnedCoins'],
            'purchasedCoins' => $earned['purchasedCoins'],
            'earnedDebt' => $earned['earnedDebt'],
            'refundDebt' => $player['refund_coin_debt'] - $changes['debtRemoved'],
        ];
    }

    private function decrementRefundDebtComponent(
        string $transactionId,
        ?string $cosmeticDebtId,
        int $amount,
    ): void {
        if ($cosmeticDebtId === null) {
            $component = $this->database->prepare(
                'UPDATE storekit_transactions SET refund_debt_outstanding = '
                . 'refund_debt_outstanding - :total_decrement, base_refund_debt_outstanding = '
                . 'base_refund_debt_outstanding - :base_decrement WHERE transaction_id = :transaction_id '
                . 'AND refund_debt_outstanding >= :total_minimum '
                . 'AND base_refund_debt_outstanding >= :base_minimum'
            );
            $component->execute([
                'total_decrement' => $amount,
                'base_decrement' => $amount,
                'total_minimum' => $amount,
                'base_minimum' => $amount,
                'transaction_id' => $transactionId,
            ]);
        } else {
            $debt = $this->database->prepare(
                'UPDATE storekit_cosmetic_restore_debts SET settled_amount = settled_amount + :increment '
                . 'WHERE debt_id = :debt_id AND released_at IS NULL '
                . 'AND amount - settled_amount >= :minimum'
            );
            $debt->bindValue(':increment', $amount, PDO::PARAM_INT);
            $debt->bindValue(':minimum', $amount, PDO::PARAM_INT);
            $debt->bindValue(':debt_id', $cosmeticDebtId);
            $debt->execute();
            if ($debt->rowCount() !== 1) {
                throw new \RuntimeException('Cosmetic refund debt changed during credit allocation.');
            }
            $component = $this->database->prepare(
                'UPDATE storekit_transactions SET refund_debt_outstanding = '
                . 'refund_debt_outstanding - :decrement WHERE transaction_id = :transaction_id '
                . 'AND refund_debt_outstanding >= :minimum'
            );
            $component->execute([
                'decrement' => $amount,
                'minimum' => $amount,
                'transaction_id' => $transactionId,
            ]);
        }
        if ($component->rowCount() !== 1) {
            throw new \RuntimeException('Refund debt changed during paid-credit allocation.');
        }
    }

    private function insertRefundDebtAllocation(
        string $playerId,
        string $sourceType,
        string $sourceReference,
        ?string $sourceTransactionId,
        ?int $sourceEconomyGeneration,
        string $refundTransactionId,
        ?string $cosmeticDebtId,
        int $amount,
    ): void {
        $allocation = $this->database->prepare(
            'INSERT INTO storekit_refund_debt_allocations '
            . '(allocation_id, player_id, source_type, source_reference, source_economy_generation, '
            . 'source_purchase_transaction_id, refund_transaction_id, cosmetic_restore_debt_id, amount) '
            . 'VALUES (:allocation_id, :player_id, :source_type, :source_reference, '
            . ':source_economy_generation, :source_transaction_id, :refund_transaction_id, '
            . ':cosmetic_restore_debt_id, :amount)'
        );
        $allocation->execute([
            'allocation_id' => Uuid::v4(),
            'player_id' => $playerId,
            'source_type' => $sourceType,
            'source_reference' => mb_strcut($sourceReference, 0, 128, 'UTF-8'),
            'source_economy_generation' => $sourceEconomyGeneration,
            'source_transaction_id' => $sourceTransactionId,
            'refund_transaction_id' => $refundTransactionId,
            'cosmetic_restore_debt_id' => $cosmeticDebtId,
            'amount' => $amount,
        ]);
    }

    /** @param array<string, int> $wallet @return array<string, int> */
    private function persist(string $playerId, array $wallet): array
    {
        $summary = CoinEconomy::summary(
            $wallet['earnedCoins'],
            $wallet['purchasedCoins'],
            $wallet['earnedDebt'],
            $wallet['refundDebt'],
        );
        $statement = $this->database->prepare(
            'UPDATE players SET earned_coins = :earned_coins, purchased_coins = :purchased_coins, '
            . 'earned_coin_debt = :earned_coin_debt, refund_coin_debt = :refund_coin_debt, '
            . 'coins = :coins, coin_debt = :coin_debt, updated_at = UTC_TIMESTAMP(3) '
            . 'WHERE id = :player_id'
        );
        $statement->execute([
            'earned_coins' => $summary['earnedCoins'],
            'purchased_coins' => $summary['purchasedCoins'],
            'earned_coin_debt' => $summary['earnedDebt'],
            'refund_coin_debt' => $summary['refundDebt'],
            'coins' => $summary['coins'],
            'coin_debt' => $summary['debt'],
            'player_id' => $playerId,
        ]);
        if ($statement->rowCount() > 1) {
            throw new \RuntimeException('Wallet update affected multiple players.');
        }
        return $summary;
    }

    private function allocatePurchasedLots(
        string $eventId,
        string $playerId,
        int $amount,
        string $purpose,
        string $reference,
    ): void {
        $lots = $this->database->prepare(
            'SELECT transaction_id, available_coins FROM purchased_coin_lots '
            . "WHERE player_id = :player_id AND status IN ('active','reinstated') AND available_coins > 0 "
            . 'ORDER BY credited_at, transaction_id FOR UPDATE'
        );
        $lots->execute(['player_id' => $playerId]);
        $remaining = $amount;
        foreach ($lots->fetchAll() as $lot) {
            if ($remaining === 0) break;
            $take = min($remaining, (int) $lot['available_coins']);
            $update = $this->database->prepare(
                'UPDATE purchased_coin_lots SET available_coins = available_coins - :available_debit, '
                . 'spent_coins = spent_coins + :spent_credit, updated_at = UTC_TIMESTAMP(3) '
                . 'WHERE transaction_id = :transaction_id AND available_coins >= :available_minimum'
            );
            $update->execute([
                'available_debit' => $take,
                'spent_credit' => $take,
                'available_minimum' => $take,
                'transaction_id' => $lot['transaction_id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Purchased lot changed during spend allocation.');
            }
            $this->insertAllocation(
                $eventId,
                $playerId,
                'purchased',
                (string) $lot['transaction_id'],
                $take,
                $purpose,
                $reference,
            );
            $remaining -= $take;
        }
        if ($remaining !== 0) {
            throw new \RuntimeException('Purchased wallet and lot availability do not reconcile.');
        }
    }

    private function insertLedger(
        string $eventId,
        string $eventKey,
        string $playerId,
        int $economyGeneration,
        string $eventType,
        int $coinDelta,
        int $earnedDelta,
        int $purchasedDelta,
        array $wallet,
        int $totalPlayMs,
        string $actor,
        string $reason,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO coin_ledger '
            . '(event_id, event_key, player_id, economy_generation, event_type, play_ms_delta, '
            . 'coin_delta, earned_delta, purchased_delta, coin_balance_after, earned_balance_after, '
            . 'purchased_balance_after, coin_debt_after, earned_debt_after, refund_debt_after, '
            . 'total_play_ms_after, coin_status, actor, reason) VALUES '
            . '(:event_id, :event_key, :player_id, :economy_generation, :event_type, 0, '
            . ':coin_delta, :earned_delta, :purchased_delta, :coin_balance_after, :earned_balance_after, '
            . ':purchased_balance_after, :coin_debt_after, :earned_debt_after, :refund_debt_after, '
            . ":total_play_ms_after, 'eligible', :actor, :reason)"
        );
        $statement->execute([
            'event_id' => $eventId,
            'event_key' => mb_strcut($eventKey, 0, 128, 'UTF-8'),
            'player_id' => $playerId,
            'economy_generation' => $economyGeneration,
            'event_type' => $eventType,
            'coin_delta' => $coinDelta,
            'earned_delta' => $earnedDelta,
            'purchased_delta' => $purchasedDelta,
            'coin_balance_after' => $wallet['coins'],
            'earned_balance_after' => $wallet['earnedCoins'],
            'purchased_balance_after' => $wallet['purchasedCoins'],
            'coin_debt_after' => $wallet['debt'],
            'earned_debt_after' => $wallet['earnedDebt'],
            'refund_debt_after' => $wallet['refundDebt'],
            'total_play_ms_after' => $totalPlayMs,
            'actor' => $actor,
            'reason' => $reason,
        ]);
    }

    private function insertAllocation(
        string $eventId,
        string $playerId,
        string $source,
        ?string $lotTransactionId,
        int $amount,
        string $purpose,
        string $reference,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO coin_spend_allocations '
            . '(allocation_id, spend_event_id, player_id, source, lot_transaction_id, amount, '
            . 'purpose, spend_reference_pseudonym) VALUES '
            . '(:allocation_id, :spend_event_id, :player_id, :source, :lot_transaction_id, :amount, '
            . ':purpose, :reference_pseudonym)'
        );
        $statement->bindValue(':allocation_id', Uuid::v4());
        $statement->bindValue(':spend_event_id', $eventId);
        $statement->bindValue(':player_id', $playerId);
        $statement->bindValue(':source', $source);
        $statement->bindValue(
            ':lot_transaction_id',
            $lotTransactionId,
            $lotTransactionId === null ? PDO::PARAM_NULL : PDO::PARAM_STR,
        );
        $statement->bindValue(':amount', $amount, PDO::PARAM_INT);
        $statement->bindValue(':purpose', $purpose);
        $statement->bindValue(':reference_pseudonym', hash('sha256', $reference, true), PDO::PARAM_LOB);
        $statement->execute();
    }
}
