<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class StoreKitService implements StoreKitNotificationProcessor
{
    public function __construct(
        private readonly PDO $database,
        private readonly Config $config,
        private readonly StoreKitProductCatalog $catalog,
        private readonly AppleJwsVerifier $verifier,
        private readonly StoreKitAccountRepository $accounts,
        private readonly CoinWalletRepository $wallets,
    ) {
    }

    public function submit(
        string $playerId,
        mixed $signedTransaction,
        mixed $submittedAppAccountToken,
    ): array {
        $this->requireConfigured();
        if (!is_string($signedTransaction) || $signedTransaction === '' || strlen($signedTransaction) > 262_144) {
            throw new ApiException(400, 'A signed App Store transaction is required.');
        }
        if (!is_string($submittedAppAccountToken) || !Uuid::isValidV4($submittedAppAccountToken)) {
            throw new ApiException(400, 'The PimPoPom App Store account binding is required.');
        }

        $serverToken = $this->accounts->appAccountToken($playerId);
        if (!hash_equals($serverToken, strtolower($submittedAppAccountToken))) {
            throw new ApiException(409, 'The App Store account binding changed. Refresh and try again.');
        }
        $payload = $this->verify($signedTransaction);
        $transaction = StoreKitTransaction::fromVerifiedPayload(
            $payload,
            $this->config,
            $this->catalog,
            $serverToken,
        );
        if ($transaction->revocationDateMs !== null) {
            return $this->refund($transaction, $signedTransaction, 'REFUND', $playerId);
        }
        return $this->record($transaction, $signedTransaction, $playerId, true);
    }

    /**
     * Process an already outer-authenticated notification transaction.
     *
     * @return array{transactionId: string, status: string, duplicate: bool}
     */
    public function processNotificationTransaction(
        string $signedTransaction,
        string $notificationType,
        ?int $notificationSignedDateMs = null,
        ?string $expectedEnvironment = null,
    ): array {
        $this->requireConfigured();
        $payload = $this->verify($signedTransaction);
        $signedToken = $payload['appAccountToken'] ?? null;
        $playerId = is_string($signedToken)
            ? $this->accounts->playerIdForToken($signedToken)
            : null;
        $expectedToken = $playerId === null ? null : $this->accounts->binding($playerId);
        $transaction = StoreKitTransaction::fromVerifiedPayload(
            $payload,
            $this->config,
            $this->catalog,
            $expectedToken,
        );
        if ($expectedEnvironment !== null && !hash_equals($expectedEnvironment, $transaction->environment)) {
            throw new ApiException(400, 'The App Store notification environments do not match.');
        }
        $lifecycleSignedDateMs = $notificationSignedDateMs ?? $transaction->signedDateMs;
        if ($lifecycleSignedDateMs < 1) {
            throw new ApiException(400, 'The App Store lifecycle signed date is invalid.');
        }
        if ($transaction->ownershipType === 'FAMILY_SHARED') {
            $playerId = $this->accounts->playerIdForFamilyAppTransaction(
                $transaction->environment,
                (string) $transaction->appTransactionId,
            );
        }

        // Reconciliation may discover revocation evidence through a current
        // transaction lookup even when the original notification was missed.
        if ($transaction->revocationDateMs !== null && $notificationType !== 'REFUND_REVERSED') {
            return $this->refund(
                $transaction,
                $signedTransaction,
                'REFUND',
                $playerId,
                $lifecycleSignedDateMs,
            );
        }

        return match ($notificationType) {
            'ONE_TIME_CHARGE' => $this->record(
                $transaction,
                $signedTransaction,
                $playerId,
                $playerId !== null,
                $lifecycleSignedDateMs,
            ),
            'REFUND', 'REVOKE' => $this->refund(
                $transaction,
                $signedTransaction,
                $notificationType,
                $playerId,
                $lifecycleSignedDateMs,
            ),
            'REFUND_REVERSED' => $this->restore(
                $transaction,
                $signedTransaction,
                $playerId,
                $lifecycleSignedDateMs,
            ),
            default => [
                'transactionId' => $transaction->appleTransactionId,
                'status' => 'ignored',
                'duplicate' => false,
            ],
        };
    }

    private function record(
        StoreKitTransaction $transaction,
        string $signedTransaction,
        ?string $playerId,
        bool $mayCredit,
        ?int $lifecycleSignedDateMs = null,
    ): array {
        $lifecycleSignedDateMs ??= $transaction->signedDateMs;
        try {
            return $this->recordOnce(
                $transaction,
                $signedTransaction,
                $playerId,
                $mayCredit,
                $lifecycleSignedDateMs,
            );
        } catch (PDOException $error) {
            if (!$this->isDuplicateKey($error)) throw $error;
            // Two first deliveries can both observe no row before one wins the
            // globally unique Apple transaction ID. Re-run the complete
            // existing-row path so Family Sharing attachment and strict
            // conflict checks remain identical to an ordinary retry.
            return $this->recordOnce(
                $transaction,
                $signedTransaction,
                $playerId,
                $mayCredit,
                $lifecycleSignedDateMs,
            );
        }
    }

    private function recordOnce(
        StoreKitTransaction $transaction,
        string $signedTransaction,
        ?string $playerId,
        bool $mayCredit,
        int $lifecycleSignedDateMs,
    ): array {
        return $this->transactional(function () use (
            $transaction,
            $signedTransaction,
            $playerId,
            $mayCredit,
            $lifecycleSignedDateMs,
        ): array {
            if ($transaction->ownershipType === 'FAMILY_SHARED' && $playerId !== null) {
                $this->accounts->bindFamilyBeneficiary(
                    $transaction->environment,
                    (string) $transaction->appTransactionId,
                    $playerId,
                );
            }
            $existing = $this->lockTransaction($transaction->transactionId);
            $pseudonym = $this->transactionAccountPseudonym($transaction, $playerId);
            if (is_array($existing)) {
                if ($this->mayAttachUncreditedPurchase(
                    $existing,
                    $transaction,
                    $playerId,
                    $mayCredit,
                )) {
                    $this->assertImmutableTransaction($existing, $transaction);
                    $storedPseudonym = $existing['account_token_pseudonym'] ?? null;
                    if (!is_string($storedPseudonym) || !hash_equals($storedPseudonym, $pseudonym)) {
                        throw new ApiException(
                            409,
                            'This App Store transaction conflicts with existing payment evidence.',
                        );
                    }
                    $this->database->prepare(
                        'UPDATE storekit_transactions SET player_id = :player_id '
                        . 'WHERE transaction_id = :transaction_id AND player_id IS NULL '
                        . 'AND account_deleted_at IS NULL AND credited_coins = 0'
                    )->execute([
                        'player_id' => $playerId,
                        'transaction_id' => $transaction->transactionId,
                    ]);
                    if ($transaction->productType === 'consumable') {
                        $player = $this->wallets->lock($playerId);
                        $grossCoins = $transaction->grossCoins();
                        $refundDebtPaid = min($player['refund_coin_debt'], $grossCoins);
                        $wallet = $this->wallets->creditPurchased($playerId, $grossCoins, $player);
                        $this->insertCoinLot(
                            $transaction,
                            $playerId,
                            $grossCoins - $refundDebtPaid,
                            $refundDebtPaid,
                            0,
                            (string) $existing['status'],
                        );
                        $this->wallets->allocateRefundDebtPayment(
                            $playerId,
                            'storekit_purchase',
                            $transaction->transactionId,
                            $transaction->transactionId,
                            $refundDebtPaid,
                        );
                        $this->insertPurchaseLedger(
                            $transaction,
                            $playerId,
                            $player,
                            $wallet,
                            $grossCoins,
                        );
                        $this->database->prepare(
                            'UPDATE storekit_transactions SET credited_coins = :credited_coins '
                            . 'WHERE transaction_id = :transaction_id'
                        )->execute([
                            'credited_coins' => $grossCoins,
                            'transaction_id' => $transaction->transactionId,
                        ]);
                    }
                    if ($transaction->catalogProduct['capability'] !== null) {
                        $this->upsertEntitlement($transaction, $playerId, true);
                    }
                    $this->advanceSignedWatermark($existing, $transaction, $lifecycleSignedDateMs);
                    $this->insertObservation(
                        $transaction,
                        $signedTransaction,
                        (string) $existing['status'],
                    );
                    return $this->payload(
                        $playerId,
                        $transaction->transactionId,
                        (string) $existing['status'],
                        false,
                    );
                }
                if ($this->mayAttachFamilyEntitlement($existing, $transaction, $playerId)) {
                    $this->assertImmutableTransaction($existing, $transaction);
                    $this->database->prepare(
                        'UPDATE storekit_transactions SET player_id = :player_id WHERE transaction_id = :transaction_id '
                        . 'AND player_id IS NULL AND credited_coins = 0'
                    )->execute([
                        'player_id' => $playerId,
                        'transaction_id' => $transaction->transactionId,
                    ]);
                    $this->upsertEntitlement($transaction, $playerId, true);
                    $this->insertObservation($transaction, $signedTransaction, 'active');
                    return $this->payload($playerId, $transaction->transactionId, 'active', false);
                }
                $this->assertSameTransaction($existing, $transaction, $pseudonym, $playerId);
                $this->advanceSignedWatermark($existing, $transaction, $lifecycleSignedDateMs);
                $this->insertObservation(
                    $transaction,
                    $signedTransaction,
                    (string) $existing['status'],
                );
                return $this->payload($playerId, $transaction->transactionId, (string) $existing['status'], true);
            }

            $initiallyRevoked = $transaction->revocationDateMs !== null;
            $this->insertTransaction(
                $transaction,
                $signedTransaction,
                $playerId,
                $pseudonym,
                $initiallyRevoked ? 'refunded' : 'active',
                0,
                $lifecycleSignedDateMs,
            );
            $this->insertObservation(
                $transaction,
                $signedTransaction,
                $initiallyRevoked ? 'refunded' : 'active',
            );
            if ($initiallyRevoked || !$mayCredit || $playerId === null) {
                if ($transaction->productType === 'consumable' && $initiallyRevoked) {
                    $this->insertCoinLot(
                        $transaction,
                        $playerId,
                        0,
                        0,
                        $transaction->grossCoins(),
                        'refunded',
                    );
                }
                if ($transaction->catalogProduct['capability'] !== null) {
                    $this->upsertEntitlement($transaction, $playerId, false);
                }
                return $this->payload(
                    $playerId,
                    $transaction->transactionId,
                    $initiallyRevoked ? 'refunded' : 'recorded',
                    false,
                );
            }

            if ($transaction->productType === 'consumable') {
                $player = $this->wallets->lock($playerId);
                $grossCoins = $transaction->grossCoins();
                $refundDebtPaid = min($player['refund_coin_debt'], $grossCoins);
                $wallet = $this->wallets->creditPurchased($playerId, $grossCoins, $player);
                $availableCoins = $grossCoins - $refundDebtPaid;
                $this->insertCoinLot(
                    $transaction,
                    $playerId,
                    $availableCoins,
                    $refundDebtPaid,
                    0,
                    'active',
                );
                $this->wallets->allocateRefundDebtPayment(
                    $playerId,
                    'storekit_purchase',
                    $transaction->transactionId,
                    $transaction->transactionId,
                    $refundDebtPaid,
                );
                $this->insertPurchaseLedger($transaction, $playerId, $player, $wallet, $grossCoins);
                $this->database->prepare(
                    'UPDATE storekit_transactions SET credited_coins = :credited_coins '
                    . 'WHERE transaction_id = :transaction_id'
                )->execute([
                    'credited_coins' => $grossCoins,
                    'transaction_id' => $transaction->transactionId,
                ]);
            }
            if ($transaction->catalogProduct['capability'] !== null) {
                $this->upsertEntitlement($transaction, $playerId, true);
            }
            return $this->payload($playerId, $transaction->transactionId, 'active', false);
        });
    }

    private function refund(
        StoreKitTransaction $transaction,
        string $signedTransaction,
        string $notificationType,
        ?string $resolvedPlayerId = null,
        ?int $lifecycleSignedDateMs = null,
    ): array {
        $lifecycleSignedDateMs ??= $transaction->signedDateMs;
        try {
            return $this->refundOnce(
                $transaction,
                $signedTransaction,
                $notificationType,
                $resolvedPlayerId,
                $lifecycleSignedDateMs,
            );
        } catch (PDOException $error) {
            if (!$this->isDuplicateKey($error)) throw $error;
            return $this->refundOnce(
                $transaction,
                $signedTransaction,
                $notificationType,
                $resolvedPlayerId,
                $lifecycleSignedDateMs,
            );
        }
    }

    private function refundOnce(
        StoreKitTransaction $transaction,
        string $signedTransaction,
        string $notificationType,
        ?string $resolvedPlayerId,
        int $lifecycleSignedDateMs,
    ): array {
        return $this->transactional(function () use (
            $transaction,
            $signedTransaction,
            $notificationType,
            $resolvedPlayerId,
            $lifecycleSignedDateMs,
        ): array {
            if ($transaction->ownershipType === 'FAMILY_SHARED' && $resolvedPlayerId !== null) {
                $this->accounts->bindFamilyBeneficiary(
                    $transaction->environment,
                    (string) $transaction->appTransactionId,
                    $resolvedPlayerId,
                );
            }
            $existing = $this->lockTransaction($transaction->transactionId);
            if (!is_array($existing)) {
                $pseudonym = $this->transactionAccountPseudonym($transaction, $resolvedPlayerId);
                $this->insertTransaction(
                    $transaction,
                    $signedTransaction,
                    $resolvedPlayerId,
                    $pseudonym,
                    'refunded',
                    1,
                    $lifecycleSignedDateMs,
                );
                $this->insertObservation($transaction, $signedTransaction, 'refunded');
                if ($transaction->productType === 'consumable') {
                    $this->insertCoinLot(
                        $transaction,
                        $resolvedPlayerId,
                        0,
                        0,
                        $transaction->grossCoins(),
                        'refunded',
                    );
                }
            if ($transaction->catalogProduct['capability'] !== null) {
                    $this->upsertEntitlement($transaction, $resolvedPlayerId, false);
                }
                return $this->payload($resolvedPlayerId, $transaction->transactionId, 'refunded', false);
            }
            $playerId = is_string($existing['player_id'] ?? null) ? $existing['player_id'] : null;
            $pseudonym = $this->pseudonymForExisting($existing, $transaction, $playerId);
            $this->assertSameTransaction($existing, $transaction, $pseudonym, $playerId);
            if ($lifecycleSignedDateMs <= (int) $existing['lifecycle_signed_date_ms']
                && !in_array($existing['status'], ['refunded', 'revoked'], true)
            ) {
                $this->insertObservation(
                    $transaction,
                    $signedTransaction,
                    (string) $existing['status'],
                );
                return $this->payload(
                    $playerId,
                    $transaction->transactionId,
                    (string) $existing['status'],
                    true,
                );
            }
            if (in_array($existing['status'], ['refunded', 'revoked'], true)) {
                $this->advanceSignedWatermark($existing, $transaction, $lifecycleSignedDateMs);
                $this->insertObservation(
                    $transaction,
                    $signedTransaction,
                    (string) $existing['status'],
                );
                return $this->payload($playerId, $transaction->transactionId, (string) $existing['status'], true);
            }

            $transitionVersion = (int) ($existing['transition_version'] ?? 0) + 1;

            if ($playerId !== null && $transaction->productType === 'consumable') {
                $this->revokeFundedCosmetics(
                    $playerId,
                    $transaction->transactionId,
                    $transitionVersion,
                );
                $this->wallets->refundLot(
                    $playerId,
                    $transaction->transactionId,
                    $transitionVersion,
                );
            } elseif ($transaction->productType === 'consumable') {
                $this->database->prepare(
                    "UPDATE purchased_coin_lots SET reversed_coins = reversed_coins + available_coins, "
                    . "available_coins = 0, status = 'refunded' WHERE transaction_id = :transaction_id"
                )->execute(['transaction_id' => $transaction->transactionId]);
            }
            $this->database->prepare(
                'UPDATE player_entitlement_sources SET active = 0, revoked_at = UTC_TIMESTAMP(3) '
                . 'WHERE source_transaction_id = :transaction_id AND active = 1'
            )->execute(['transaction_id' => $transaction->transactionId]);
            $status = $notificationType === 'REVOKE' ? 'revoked' : 'refunded';
            $this->database->prepare(
                'UPDATE storekit_transactions SET status = :status, '
                . 'signed_date_ms = CASE WHEN signed_date_ms < :new_signed_date_ms '
                . 'THEN :replacement_signed_date_ms ELSE signed_date_ms END, '
                . 'lifecycle_signed_date_ms = :lifecycle_signed_date_ms, '
                . 'revocation_date_ms = :revocation_date_ms, '
                . 'revocation_reason = :revocation_reason, '
                . 'transition_version = :transition_version '
                . 'WHERE transaction_id = :transaction_id'
            )->execute([
                'status' => $status,
                'new_signed_date_ms' => $transaction->signedDateMs,
                'replacement_signed_date_ms' => $transaction->signedDateMs,
                'lifecycle_signed_date_ms' => $lifecycleSignedDateMs,
                'revocation_date_ms' => $transaction->revocationDateMs,
                'revocation_reason' => $transaction->revocationReason,
                'transition_version' => $transitionVersion,
                'transaction_id' => $transaction->transactionId,
            ]);
            $this->insertObservation($transaction, $signedTransaction, $status);
            return $this->payload($playerId, $transaction->transactionId, $status, false);
        });
    }

    private function restore(
        StoreKitTransaction $transaction,
        string $signedTransaction,
        ?string $resolvedPlayerId = null,
        ?int $lifecycleSignedDateMs = null,
    ): array {
        $lifecycleSignedDateMs ??= $transaction->signedDateMs;
        try {
            return $this->restoreOnce(
                $transaction,
                $signedTransaction,
                $resolvedPlayerId,
                $lifecycleSignedDateMs,
            );
        } catch (PDOException $error) {
            if (!$this->isDuplicateKey($error)) throw $error;
            return $this->restoreOnce(
                $transaction,
                $signedTransaction,
                $resolvedPlayerId,
                $lifecycleSignedDateMs,
            );
        }
    }

    private function restoreOnce(
        StoreKitTransaction $transaction,
        string $signedTransaction,
        ?string $resolvedPlayerId,
        int $lifecycleSignedDateMs,
    ): array {
        return $this->transactional(function () use (
            $transaction,
            $signedTransaction,
            $resolvedPlayerId,
            $lifecycleSignedDateMs,
        ): array {
            if ($transaction->ownershipType === 'FAMILY_SHARED' && $resolvedPlayerId !== null) {
                $this->accounts->bindFamilyBeneficiary(
                    $transaction->environment,
                    (string) $transaction->appTransactionId,
                    $resolvedPlayerId,
                );
            }
            $existing = $this->lockTransaction($transaction->transactionId);
            if (!is_array($existing)) {
                $pseudonym = $this->transactionAccountPseudonym($transaction, $resolvedPlayerId);
                $this->insertTransaction(
                    $transaction,
                    $signedTransaction,
                    $resolvedPlayerId,
                    $pseudonym,
                    'reinstated',
                    1,
                    $lifecycleSignedDateMs,
                );
                $this->database->prepare(
                    'UPDATE storekit_transactions SET revocation_date_ms = NULL, revocation_reason = NULL '
                    . 'WHERE transaction_id = :transaction_id'
                )->execute(['transaction_id' => $transaction->transactionId]);
                $this->insertObservation($transaction, $signedTransaction, 'reinstated');

                if ($resolvedPlayerId !== null && $transaction->productType === 'consumable') {
                    $player = $this->wallets->lock($resolvedPlayerId);
                    $grossCoins = $transaction->grossCoins();
                    $refundDebtPaid = min($player['refund_coin_debt'], $grossCoins);
                    $wallet = $this->wallets->creditPurchased($resolvedPlayerId, $grossCoins, $player);
                    $this->insertCoinLot(
                        $transaction,
                        $resolvedPlayerId,
                        $grossCoins - $refundDebtPaid,
                        $refundDebtPaid,
                        0,
                        'reinstated',
                    );
                    $this->wallets->allocateRefundDebtPayment(
                        $resolvedPlayerId,
                        'storekit_purchase',
                        $transaction->transactionId,
                        $transaction->transactionId,
                        $refundDebtPaid,
                    );
                    $this->insertPurchaseLedger(
                        $transaction,
                        $resolvedPlayerId,
                        $player,
                        $wallet,
                        $grossCoins,
                    );
                    $this->database->prepare(
                        'UPDATE storekit_transactions SET credited_coins = :credited_coins '
                        . 'WHERE transaction_id = :transaction_id'
                    )->execute([
                        'credited_coins' => $grossCoins,
                        'transaction_id' => $transaction->transactionId,
                    ]);
                }
                if ($transaction->catalogProduct['capability'] !== null) {
                    $this->upsertEntitlement($transaction, $resolvedPlayerId, true);
                }
                return $this->payload(
                    $resolvedPlayerId,
                    $transaction->transactionId,
                    'reinstated',
                    false,
                );
            }
            $playerId = is_string($existing['player_id'] ?? null) ? $existing['player_id'] : null;
            $pseudonym = $this->pseudonymForExisting($existing, $transaction, $playerId);
            $this->assertSameTransaction($existing, $transaction, $pseudonym, $playerId);
            if ($lifecycleSignedDateMs <= (int) $existing['lifecycle_signed_date_ms']
                && !in_array($existing['status'], ['active', 'reinstated'], true)
            ) {
                $this->insertObservation(
                    $transaction,
                    $signedTransaction,
                    (string) $existing['status'],
                );
                return $this->payload(
                    $playerId,
                    $transaction->transactionId,
                    (string) $existing['status'],
                    true,
                );
            }
            if (in_array($existing['status'], ['active', 'reinstated'], true)) {
                $this->advanceSignedWatermark($existing, $transaction, $lifecycleSignedDateMs);
                $this->insertObservation(
                    $transaction,
                    $signedTransaction,
                    (string) $existing['status'],
                );
                return $this->payload($playerId, $transaction->transactionId, (string) $existing['status'], true);
            }

            $transitionVersion = (int) ($existing['transition_version'] ?? 0) + 1;

            if ($playerId !== null && $transaction->productType === 'consumable') {
                $this->wallets->restoreRefundedLot(
                    $playerId,
                    $transaction->transactionId,
                    $transitionVersion,
                );
                $this->restoreRefundedCosmetics($playerId, $transaction->transactionId);
                if ((int) ($existing['credited_coins'] ?? 0) === 0) {
                    $this->database->prepare(
                        'UPDATE storekit_transactions SET credited_coins = :credited_coins '
                        . 'WHERE transaction_id = :transaction_id'
                    )->execute([
                        'credited_coins' => $transaction->grossCoins(),
                        'transaction_id' => $transaction->transactionId,
                    ]);
                }
            } elseif ($transaction->productType === 'consumable') {
                // A deleted account is never silently recreated or rebound.
                $this->database->prepare(
                    "UPDATE purchased_coin_lots SET status = 'reinstated' "
                    . 'WHERE transaction_id = :transaction_id'
                )->execute(['transaction_id' => $transaction->transactionId]);
            }
            $this->database->prepare(
                'UPDATE player_entitlement_sources SET active = 1, revoked_at = NULL '
                . 'WHERE source_transaction_id = :transaction_id AND player_id IS NOT NULL'
            )->execute(['transaction_id' => $transaction->transactionId]);
            $this->database->prepare(
                "UPDATE storekit_transactions SET status = 'reinstated', "
                . 'signed_date_ms = CASE WHEN signed_date_ms < :new_signed_date_ms '
                . 'THEN :replacement_signed_date_ms ELSE signed_date_ms END, '
                . 'lifecycle_signed_date_ms = :lifecycle_signed_date_ms, '
                . 'revocation_date_ms = NULL, '
                . 'revocation_reason = NULL, '
                . 'transition_version = :transition_version WHERE transaction_id = :transaction_id'
            )->execute([
                'new_signed_date_ms' => $transaction->signedDateMs,
                'replacement_signed_date_ms' => $transaction->signedDateMs,
                'lifecycle_signed_date_ms' => $lifecycleSignedDateMs,
                'transition_version' => $transitionVersion,
                'transaction_id' => $transaction->transactionId,
            ]);
            $this->insertObservation($transaction, $signedTransaction, 'reinstated');
            return $this->payload($playerId, $transaction->transactionId, 'reinstated', false);
        });
    }

    private function revokeFundedCosmetics(
        string $playerId,
        string $transactionId,
        int $refundCycle,
    ): void
    {
        $events = $this->database->prepare(
            "SELECT DISTINCT allocation.spend_event_id FROM coin_spend_allocations allocation "
            . 'WHERE allocation.player_id = :player_id AND allocation.source = \'purchased\' '
            . 'AND allocation.lot_transaction_id = :transaction_id AND allocation.released_at IS NULL '
            . "AND allocation.purpose IN ('pet_purchase','theme_purchase') FOR UPDATE"
        );
        $events->execute(['player_id' => $playerId, 'transaction_id' => $transactionId]);
        $purchaseEventIds = array_values(array_filter(
            array_map('strval', $events->fetchAll(PDO::FETCH_COLUMN)),
        ));
        $debtEvents = $this->database->prepare(
            'SELECT purchase_event_id FROM storekit_cosmetic_restore_debts '
            . 'WHERE player_id = :player_id AND refund_transaction_id = :transaction_id '
            . 'AND released_at IS NULL FOR UPDATE'
        );
        $debtEvents->execute(['player_id' => $playerId, 'transaction_id' => $transactionId]);
        foreach ($debtEvents->fetchAll(PDO::FETCH_COLUMN) as $purchaseEventId) {
            if (is_string($purchaseEventId) && !in_array($purchaseEventId, $purchaseEventIds, true)) {
                $purchaseEventIds[] = $purchaseEventId;
            }
        }
        foreach ($purchaseEventIds as $purchaseEventId) {
            if (!is_string($purchaseEventId)) continue;
            $pet = $this->database->prepare(
                'SELECT pet_id AS item_id, price_paid FROM player_pets '
                . 'WHERE player_id = :player_id AND purchase_event_id = :purchase_event_id FOR UPDATE'
            );
            $pet->execute(['player_id' => $playerId, 'purchase_event_id' => $purchaseEventId]);
            $item = $pet->fetch();
            $itemType = 'pet';
            if (!is_array($item)) {
                $theme = $this->database->prepare(
                    'SELECT theme_id AS item_id, price_paid FROM player_themes '
                    . 'WHERE player_id = :player_id AND purchase_event_id = :purchase_event_id FOR UPDATE'
                );
                $theme->execute(['player_id' => $playerId, 'purchase_event_id' => $purchaseEventId]);
                $item = $theme->fetch();
                $itemType = 'theme';
            }
            if (!is_array($item)) continue;

            if ($this->hasActiveSpendAllocation($playerId, $purchaseEventId)) {
                $this->wallets->reverseCosmeticSpend($playerId, $purchaseEventId, $transactionId);
            }
            $this->wallets->cancelCosmeticRestoreDebt(
                $playerId,
                $transactionId,
                $purchaseEventId,
            );
            $revocation = $this->database->prepare(
                'INSERT IGNORE INTO storekit_refund_cosmetics '
                . '(revocation_id, refund_transaction_id, player_id, purchase_event_id, refund_cycle, '
                . 'item_type, item_id, price_paid) VALUES '
                . '(:revocation_id, :refund_transaction_id, :player_id, :purchase_event_id, '
                . ':refund_cycle, :item_type, :item_id, :price_paid)'
            );
            $revocation->execute([
                'revocation_id' => Uuid::v4(),
                'refund_transaction_id' => $transactionId,
                'player_id' => $playerId,
                'purchase_event_id' => $purchaseEventId,
                'refund_cycle' => $refundCycle,
                'item_type' => $itemType,
                'item_id' => $item['item_id'],
                'price_paid' => $item['price_paid'],
            ]);
            if ($itemType === 'pet') {
                $this->database->prepare(
                    'DELETE FROM player_pet_selection WHERE player_id = :player_id AND pet_id = :item_id'
                )->execute(['player_id' => $playerId, 'item_id' => $item['item_id']]);
                $this->database->prepare(
                    'DELETE FROM player_pets WHERE player_id = :player_id AND pet_id = :item_id'
                )->execute(['player_id' => $playerId, 'item_id' => $item['item_id']]);
            } else {
                $this->database->prepare(
                    'DELETE FROM player_theme_selection WHERE player_id = :player_id AND theme_id = :item_id'
                )->execute(['player_id' => $playerId, 'item_id' => $item['item_id']]);
                $this->database->prepare(
                    'DELETE FROM player_themes WHERE player_id = :player_id AND theme_id = :item_id'
                )->execute(['player_id' => $playerId, 'item_id' => $item['item_id']]);
            }
        }
    }

    private function restoreRefundedCosmetics(string $playerId, string $transactionId): void
    {
        $statement = $this->database->prepare(
            'SELECT * FROM storekit_refund_cosmetics WHERE refund_transaction_id = :transaction_id '
            . 'AND player_id = :player_id AND restored_at IS NULL ORDER BY created_at, revocation_id FOR UPDATE'
        );
        $statement->execute(['transaction_id' => $transactionId, 'player_id' => $playerId]);
        foreach ($statement->fetchAll() as $revocation) {
            if ($this->ownsCosmetic(
                $playerId,
                (string) $revocation['item_type'],
                (string) $revocation['item_id'],
            )) {
                $this->markCosmeticRestored((string) $revocation['revocation_id']);
                continue;
            }
            $spend = $this->wallets->forceCosmeticRestoreSpend(
                $playerId,
                (int) $revocation['price_paid'],
                (string) $revocation['item_type'],
                (string) $revocation['item_id'],
                $transactionId,
            );
            if ($revocation['item_type'] === 'pet') {
                $this->database->prepare(
                    'INSERT INTO player_pets '
                    . '(player_id, pet_id, price_paid, acquisition_source, purchase_event_id) '
                    . "VALUES (:player_id, :item_id, :price_paid, 'purchase', :purchase_event_id) "
                    . 'ON DUPLICATE KEY UPDATE purchase_event_id = VALUES(purchase_event_id)'
                )->execute([
                    'player_id' => $playerId,
                    'item_id' => $revocation['item_id'],
                    'price_paid' => $revocation['price_paid'],
                    'purchase_event_id' => $spend['eventId'],
                ]);
            } else {
                $this->database->prepare(
                    'INSERT INTO player_themes (player_id, theme_id, price_paid, purchase_event_id) '
                    . 'VALUES (:player_id, :item_id, :price_paid, :purchase_event_id) '
                    . 'ON DUPLICATE KEY UPDATE purchase_event_id = VALUES(purchase_event_id)'
                )->execute([
                    'player_id' => $playerId,
                    'item_id' => $revocation['item_id'],
                    'price_paid' => $revocation['price_paid'],
                    'purchase_event_id' => $spend['eventId'],
                ]);
            }
            $this->markCosmeticRestored((string) $revocation['revocation_id']);
        }
    }

    private function ownsCosmetic(string $playerId, string $itemType, string $itemId): bool
    {
        $table = $itemType === 'pet' ? 'player_pets' : 'player_themes';
        $column = $itemType === 'pet' ? 'pet_id' : 'theme_id';
        $statement = $this->database->prepare(
            'SELECT 1 FROM ' . $table . ' WHERE player_id = :player_id AND '
            . $column . ' = :item_id FOR UPDATE'
        );
        $statement->execute(['player_id' => $playerId, 'item_id' => $itemId]);
        return $statement->fetchColumn() !== false;
    }

    private function hasActiveSpendAllocation(string $playerId, string $purchaseEventId): bool
    {
        $statement = $this->database->prepare(
            'SELECT 1 FROM coin_spend_allocations WHERE player_id = :player_id '
            . 'AND spend_event_id = :purchase_event_id AND released_at IS NULL LIMIT 1 FOR UPDATE'
        );
        $statement->execute([
            'player_id' => $playerId,
            'purchase_event_id' => $purchaseEventId,
        ]);
        return $statement->fetchColumn() !== false;
    }

    private function markCosmeticRestored(string $revocationId): void
    {
        $this->database->prepare(
            'UPDATE storekit_refund_cosmetics SET restored_at = UTC_TIMESTAMP(3) '
            . 'WHERE revocation_id = :revocation_id AND restored_at IS NULL'
        )->execute(['revocation_id' => $revocationId]);
    }

    private function insertTransaction(
        StoreKitTransaction $transaction,
        string $signedTransaction,
        ?string $playerId,
        string $pseudonym,
        string $status,
        int $transitionVersion = 0,
        ?int $lifecycleSignedDateMs = null,
    ): void {
        $lifecycleSignedDateMs ??= $transaction->signedDateMs;
        $statement = $this->database->prepare(
            'INSERT INTO storekit_transactions '
            . '(transaction_id, apple_transaction_id, original_transaction_id, app_transaction_id, app_transaction_pseudonym, player_id, '
            . 'account_token_pseudonym, product_id, product_type, ownership_type, environment, bundle_id, '
            . 'app_apple_id, signed_quantity, purchase_date_ms, signed_date_ms, lifecycle_signed_date_ms, revocation_date_ms, '
            . 'revocation_reason, status, transition_version, payload_hash) VALUES '
            . '(:transaction_id, :apple_transaction_id, :original_transaction_id, :app_transaction_id, :app_transaction_pseudonym, :player_id, '
            . ':account_token_pseudonym, :product_id, :product_type, :ownership_type, :environment, :bundle_id, '
            . ':app_apple_id, :signed_quantity, :purchase_date_ms, :signed_date_ms, :lifecycle_signed_date_ms, :revocation_date_ms, '
            . ':revocation_reason, :status, :transition_version, :payload_hash)'
        );
        $values = [
            'transaction_id' => $transaction->transactionId,
            'apple_transaction_id' => $transaction->appleTransactionId,
            'original_transaction_id' => $transaction->originalTransactionId,
            'app_transaction_id' => $transaction->appTransactionId,
            'player_id' => $playerId,
            'product_id' => $transaction->productId,
            'product_type' => $transaction->productType,
            'ownership_type' => $transaction->ownershipType,
            'environment' => $transaction->environment,
            'bundle_id' => $transaction->bundleId,
            'app_apple_id' => $this->config->storeKitAppAppleId,
            'signed_quantity' => $transaction->quantity,
            'purchase_date_ms' => $transaction->purchaseDateMs,
            'signed_date_ms' => $transaction->signedDateMs,
            'lifecycle_signed_date_ms' => $lifecycleSignedDateMs,
            'revocation_date_ms' => $transaction->revocationDateMs,
            'revocation_reason' => $transaction->revocationReason,
            'status' => $status,
            'transition_version' => $transitionVersion,
        ];
        foreach ($values as $key => $value) {
            $statement->bindValue(':' . $key, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $statement->bindValue(
            ':app_transaction_pseudonym',
            $transaction->appTransactionId === null
                ? null
                : $this->accounts->familyPseudonym($transaction->appTransactionId),
            $transaction->appTransactionId === null ? PDO::PARAM_NULL : PDO::PARAM_LOB,
        );
        $statement->bindValue(':account_token_pseudonym', $pseudonym, PDO::PARAM_LOB);
        $statement->bindValue(':payload_hash', hash('sha256', $signedTransaction, true), PDO::PARAM_LOB);
        $statement->execute();
    }

    private function insertCoinLot(
        StoreKitTransaction $transaction,
        ?string $playerId,
        int $availableCoins,
        int $refundDebtSettled,
        int $reversedCoins,
        string $status,
    ): void {
        $this->database->prepare(
            'INSERT INTO purchased_coin_lots '
            . '(transaction_id, player_id, gross_coins, available_coins, spent_coins, '
            . 'refund_debt_settled_coins, reversed_coins, status) VALUES '
            . '(:transaction_id, :player_id, :gross_coins, :available_coins, 0, '
            . ':refund_debt_settled_coins, :reversed_coins, :status)'
        )->execute([
            'transaction_id' => $transaction->transactionId,
            'player_id' => $playerId,
            'gross_coins' => $transaction->grossCoins(),
            'available_coins' => $availableCoins,
            'refund_debt_settled_coins' => $refundDebtSettled,
            'reversed_coins' => $reversedCoins,
            'status' => $status,
        ]);
    }

    private function upsertEntitlement(
        StoreKitTransaction $transaction,
        ?string $playerId,
        bool $active,
    ): void {
        $capability = $transaction->catalogProduct['capability'];
        if (!is_string($capability) || $playerId === null) return;
        $this->database->prepare(
            'INSERT INTO player_entitlement_sources '
            . '(source_id, player_id, capability, source_type, source_transaction_id, active, revoked_at) '
            . 'VALUES (:source_id, :player_id, :capability, :source_type, :source_transaction_id, '
            . ':active, :revoked_at) ON DUPLICATE KEY UPDATE active = VALUES(active), '
            . 'revoked_at = VALUES(revoked_at), player_id = COALESCE(player_id, VALUES(player_id))'
        )->execute([
            'source_id' => Uuid::v4(),
            'player_id' => $playerId,
            'capability' => $capability,
            'source_type' => $transaction->productType === 'consumable'
                ? 'coin_pack'
                : ($transaction->ownershipType === 'FAMILY_SHARED' ? 'family_shared' : 'non_consumable'),
            'source_transaction_id' => $transaction->transactionId,
            'active' => $active ? 1 : 0,
            'revoked_at' => $active ? null : gmdate('Y-m-d H:i:s.v'),
        ]);
    }

    private function insertPurchaseLedger(
        StoreKitTransaction $transaction,
        string $playerId,
        array $player,
        array $wallet,
        int $grossCoins,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO coin_ledger '
            . '(event_id, event_key, player_id, economy_generation, event_type, play_ms_delta, '
            . 'coin_delta, earned_delta, purchased_delta, coin_balance_after, earned_balance_after, '
            . 'purchased_balance_after, coin_debt_after, earned_debt_after, refund_debt_after, '
            . 'total_play_ms_after, coin_status, actor, reason) VALUES '
            . '(:event_id, :event_key, :player_id, :economy_generation, \'storekit_purchase\', 0, '
            . ':coin_delta, 0, :purchased_delta, :coin_balance_after, :earned_balance_after, '
            . ':purchased_balance_after, :coin_debt_after, :earned_debt_after, :refund_debt_after, '
            . ":total_play_ms_after, 'eligible', 'storekit-verifier', 'Verified App Store coin purchase.')"
        );
        $statement->execute([
            'event_id' => Uuid::v4(),
            'event_key' => 'storekit:' . hash('sha256', $transaction->transactionId),
            'player_id' => $playerId,
            'economy_generation' => $player['economy_generation'],
            'coin_delta' => $grossCoins,
            'purchased_delta' => $grossCoins,
            'coin_balance_after' => $wallet['coins'],
            'earned_balance_after' => $wallet['earnedCoins'],
            'purchased_balance_after' => $wallet['purchasedCoins'],
            'coin_debt_after' => $wallet['debt'],
            'earned_debt_after' => $wallet['earnedDebt'],
            'refund_debt_after' => $wallet['refundDebt'],
            'total_play_ms_after' => $player['total_play_ms'],
        ]);
    }

    private function lockTransaction(string $transactionId): array|false
    {
        $statement = $this->database->prepare(
            'SELECT * FROM storekit_transactions WHERE transaction_id = :transaction_id FOR UPDATE'
        );
        $statement->execute(['transaction_id' => $transactionId]);
        return $statement->fetch();
    }

    private function assertSameTransaction(
        array $stored,
        StoreKitTransaction $transaction,
        string $pseudonym,
        ?string $playerId,
    ): void {
        $storedPseudonym = $stored['account_token_pseudonym'] ?? null;
        $this->assertImmutableTransaction($stored, $transaction);
        if (
            !is_string($storedPseudonym)
            || !hash_equals($storedPseudonym, $pseudonym)
        ) {
            throw new ApiException(409, 'This App Store transaction conflicts with existing payment evidence.');
        }
        if ($playerId !== null && !hash_equals((string) ($stored['player_id'] ?? ''), $playerId)) {
            throw new ApiException(409, 'This App Store transaction belongs to another PimPoPom profile.');
        }
    }

    private function assertImmutableTransaction(array $stored, StoreKitTransaction $transaction): void
    {
        $storedAppTransactionId = $stored['app_transaction_id'] ?? null;
        $storedAppTransactionPseudonym = $stored['app_transaction_pseudonym'] ?? null;
        $appTransactionMatches = $transaction->appTransactionId === null
            ? $storedAppTransactionId === null && $storedAppTransactionPseudonym === null
            : (is_string($storedAppTransactionId)
                ? hash_equals($storedAppTransactionId, $transaction->appTransactionId)
                : is_string($storedAppTransactionPseudonym)
                    && hash_equals(
                        $storedAppTransactionPseudonym,
                        $this->accounts->familyPseudonym($transaction->appTransactionId),
                    ));
        if (
            !hash_equals(
                (string) ($stored['apple_transaction_id'] ?? StoreKitTransaction::appleIdFromStorage(
                    (string) ($stored['transaction_id'] ?? ''),
                )),
                $transaction->appleTransactionId,
            )
            || !hash_equals((string) $stored['original_transaction_id'], $transaction->originalTransactionId)
            || !hash_equals((string) $stored['product_id'], $transaction->productId)
            || !hash_equals((string) $stored['product_type'], $transaction->productType)
            || !hash_equals((string) $stored['ownership_type'], $transaction->ownershipType)
            || !hash_equals((string) $stored['bundle_id'], $transaction->bundleId)
            || !hash_equals((string) $stored['environment'], $transaction->environment)
            || ($this->config->storeKitAppAppleId !== null
                && !hash_equals(
                    $this->config->storeKitAppAppleId,
                    (string) ($stored['app_apple_id'] ?? ''),
                ))
            || (int) $stored['signed_quantity'] !== $transaction->quantity
            || (int) $stored['purchase_date_ms'] !== $transaction->purchaseDateMs
            || !$appTransactionMatches
        ) {
            throw new ApiException(409, 'This App Store transaction conflicts with existing payment evidence.');
        }
    }

    private function pseudonymForExisting(
        array $stored,
        StoreKitTransaction $transaction,
        ?string $playerId,
    ): string {
        if ($transaction->ownershipType === 'FAMILY_SHARED') {
            return $this->accounts->familyPseudonym((string) $transaction->appTransactionId);
        }
        $identifier = $transaction->appAccountToken;
        if ($identifier === null && $playerId !== null) {
            $identifier = $this->accounts->binding($playerId);
        }
        if ($identifier !== null) {
            return $this->accountPseudonym($identifier);
        }
        $storedPseudonym = $stored['account_token_pseudonym'] ?? null;
        if (!is_string($storedPseudonym) || strlen($storedPseudonym) !== 32) {
            throw new ApiException(409, 'Stored App Store payment evidence is invalid.');
        }
        return $storedPseudonym;
    }

    private function mayAttachFamilyEntitlement(
        array $stored,
        StoreKitTransaction $transaction,
        ?string $playerId,
    ): bool {
        return $playerId !== null
            && ($stored['player_id'] ?? null) === null
            && ($stored['account_deleted_at'] ?? null) === null
            && (int) ($stored['credited_coins'] ?? 0) === 0
            && in_array((string) ($stored['status'] ?? ''), ['active', 'reinstated'], true)
            && $transaction->productType === 'non_consumable'
            && $transaction->ownershipType === 'FAMILY_SHARED';
    }

    private function mayAttachUncreditedPurchase(
        array $stored,
        StoreKitTransaction $transaction,
        ?string $playerId,
        bool $mayCredit,
    ): bool {
        if (!$mayCredit || $playerId === null
            || ($stored['player_id'] ?? null) !== null
            || ($stored['account_deleted_at'] ?? null) !== null
            || (int) ($stored['credited_coins'] ?? 0) !== 0
            || !in_array((string) ($stored['status'] ?? ''), ['active', 'reinstated'], true)
            || $transaction->ownershipType !== 'PURCHASED'
            || $transaction->appAccountToken === null
        ) {
            return false;
        }
        $binding = $this->accounts->binding($playerId);
        return is_string($binding) && hash_equals($binding, $transaction->appAccountToken);
    }

    private function insertObservation(
        StoreKitTransaction $transaction,
        string $signedTransaction,
        string $state,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO storekit_transaction_observations '
            . '(observation_id, transaction_id, observed_state, signed_date_ms, '
            . 'revocation_date_ms, payload_hash) VALUES '
            . '(:observation_id, :transaction_id, :observed_state, :signed_date_ms, '
            . ':revocation_date_ms, :payload_hash) '
            . 'ON DUPLICATE KEY UPDATE observation_id = observation_id'
        );
        $statement->bindValue(':observation_id', Uuid::v4());
        $statement->bindValue(':transaction_id', $transaction->transactionId);
        $statement->bindValue(':observed_state', $state);
        $statement->bindValue(':signed_date_ms', $transaction->signedDateMs, PDO::PARAM_INT);
        $statement->bindValue(
            ':revocation_date_ms',
            $transaction->revocationDateMs,
            $transaction->revocationDateMs === null ? PDO::PARAM_NULL : PDO::PARAM_INT,
        );
        $statement->bindValue(
            ':payload_hash',
            hash('sha256', $signedTransaction, true),
            PDO::PARAM_LOB,
        );
        $statement->execute();
    }

    private function advanceSignedWatermark(
        array $stored,
        StoreKitTransaction $transaction,
        int $lifecycleSignedDateMs,
    ): void
    {
        $storedSignedDateMs = (int) ($stored['signed_date_ms'] ?? 0);
        $storedLifecycleSignedDateMs = (int) ($stored['lifecycle_signed_date_ms'] ?? 0);
        if ($transaction->signedDateMs <= $storedSignedDateMs
            && $lifecycleSignedDateMs <= $storedLifecycleSignedDateMs
        ) {
            return;
        }
        $statement = $this->database->prepare(
            'UPDATE storekit_transactions SET '
            . 'signed_date_ms = CASE WHEN signed_date_ms < :new_signed_date_ms '
            . 'THEN :replacement_signed_date_ms ELSE signed_date_ms END, '
            . 'lifecycle_signed_date_ms = CASE WHEN lifecycle_signed_date_ms < :new_lifecycle_signed_date_ms '
            . 'THEN :replacement_lifecycle_signed_date_ms ELSE lifecycle_signed_date_ms END '
            . 'WHERE transaction_id = :transaction_id'
        );
        $statement->bindValue(':new_signed_date_ms', $transaction->signedDateMs, PDO::PARAM_INT);
        $statement->bindValue(':replacement_signed_date_ms', $transaction->signedDateMs, PDO::PARAM_INT);
        $statement->bindValue(':new_lifecycle_signed_date_ms', $lifecycleSignedDateMs, PDO::PARAM_INT);
        $statement->bindValue(':replacement_lifecycle_signed_date_ms', $lifecycleSignedDateMs, PDO::PARAM_INT);
        $statement->bindValue(':transaction_id', $transaction->transactionId);
        $statement->execute();
    }

    private function accountPseudonym(?string $identifier): string
    {
        $key = $this->config->storeKitRetentionHmacKey;
        if (!is_string($key) || strlen($key) < 32 || !is_string($identifier) || $identifier === '') {
            throw new ApiException(503, 'StoreKit retention configuration is incomplete.');
        }
        return StoreKitPseudonym::account($key, $identifier);
    }

    private function transactionAccountPseudonym(
        StoreKitTransaction $transaction,
        ?string $playerId,
    ): string {
        if ($transaction->ownershipType === 'FAMILY_SHARED') {
            return $this->accounts->familyPseudonym((string) $transaction->appTransactionId);
        }
        $identifier = $transaction->appAccountToken
            ?? ($playerId === null ? null : $this->accounts->binding($playerId));
        return $this->accountPseudonym($identifier);
    }

    private function verify(string $signedTransaction): array
    {
        try {
            return $this->verifier->verify($signedTransaction);
        } catch (AppleJwsVerificationException $error) {
            throw new ApiException(400, 'The App Store signature could not be verified.');
        }
    }

    private function requireConfigured(): void
    {
        if (!$this->config->storeKitIsConfigured() || $this->catalog->isEmpty()) {
            throw new ApiException(503, 'StoreKit is not configured.');
        }
    }

    private function payload(?string $playerId, string $transactionId, string $status, bool $duplicate): array
    {
        return [
            'transactionId' => StoreKitTransaction::appleIdFromStorage($transactionId),
            'status' => $status,
            'duplicate' => $duplicate,
            'wallet' => $playerId === null ? null : $this->accounts->walletSummary($playerId),
            'adFree' => $playerId !== null && $this->accounts->hasEntitlement($playerId, 'ad_free'),
        ];
    }

    private function transactional(callable $operation): array
    {
        if ($this->database->inTransaction()) {
            throw new \LogicException('StoreKit service operations must own their transaction.');
        }
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->database->beginTransaction();
            try {
                $result = $operation();
                $this->database->commit();
                return $result;
            } catch (PDOException $error) {
                if ($this->database->inTransaction()) $this->database->rollBack();
                if ($attempt < 3 && $this->isRetryableTransactionError($error)) {
                    continue;
                }
                throw $error;
            } catch (Throwable $error) {
                if ($this->database->inTransaction()) $this->database->rollBack();
                throw $error;
            }
        }
        throw new \RuntimeException('StoreKit transaction retry loop ended unexpectedly.');
    }

    private function isDuplicateKey(PDOException $error): bool
    {
        return $error->getCode() === '23000'
            && (int) ($error->errorInfo[1] ?? 0) === 1062;
    }

    private function isRetryableTransactionError(PDOException $error): bool
    {
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        return $error->getCode() === '40001' || in_array($driverCode, [1205, 1213], true);
    }
}
