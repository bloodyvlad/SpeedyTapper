<?php

declare(strict_types=1);

namespace SpeedyTapper;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Removes a player's game identity while retaining only detached StoreKit
 * accounting evidence needed to process later refunds and reversals.
 *
 * The HTTP boundary remains responsible for recent-authentication, CSRF,
 * explicit-confirmation, and destroying the current PHP session after this
 * transaction commits.
 */
final class AccountDeletionService
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $retentionHmacKey,
    ) {
        if ($this->retentionHmacKey !== '' && strlen($this->retentionHmacKey) < 32) {
            throw new InvalidArgumentException(
                'The account-deletion retention HMAC key must contain at least 32 bytes.'
            );
        }
    }

    /**
     * @return array{
     *     deleted: true,
     *     retainedStoreKitTransactions: int,
     *     retainedPurchasedCoinLots: int,
     *     retainedEntitlementSources: int,
     *     retainedPurchasedSpendAllocations: int,
     *     retainedRefundDebtSettlements: int,
     *     retainedRefundedCosmetics: int,
     *     retainedCosmeticRestoreDebts: int
     * }
     */
    public function delete(string $playerId): array
    {
        $playerId = strtolower(trim($playerId));
        if (!Uuid::isValidV4($playerId)) {
            throw new ApiException(401, 'Sign in again before deleting this account.');
        }
        if ($this->database->inTransaction()) {
            throw new InvalidArgumentException('Account deletion must own its database transaction.');
        }

        $this->database->beginTransaction();
        try {
            $this->lockPlayer($playerId);
            $appAccountToken = $this->appAccountToken($playerId);
            $hasPurchasedAllocations = $this->hasPurchasedSpendAllocations($playerId);
            $hasEarnedRefundSettlements = $this->hasEarnedRefundDebtSettlements($playerId);
            $hasRetainedPaymentEvidence = $this->hasStoreKitTransactions($playerId)
                || $hasPurchasedAllocations
                || $hasEarnedRefundSettlements;
            if ($hasRetainedPaymentEvidence
                && strlen($this->retentionHmacKey) < 32
            ) {
                throw new ApiException(503, 'Account deletion retention is not configured.');
            }
            $retentionPseudonym = strlen($this->retentionHmacKey) >= 32
                ? StoreKitPseudonym::account(
                    $this->retentionHmacKey,
                    $appAccountToken ?? $playerId,
                )
                : null;

            if ($hasEarnedRefundSettlements) {
                $this->pseudonymizeEarnedRefundDebtSettlements($playerId);
            }

            $retainedTransactions = $this->detachStoreKitTransactions($playerId);
            $this->tombstoneFamilyBindings($playerId);
            $retainedLots = $this->detachByPlayer('purchased_coin_lots', $playerId);
            $retainedEntitlements = $this->detachByPlayer('player_entitlement_sources', $playerId);
            $retainedRefundedCosmetics = $this->detachByPlayer('storekit_refund_cosmetics', $playerId);
            $retainedRefundDebtSettlements = $this->detachByPlayer(
                'storekit_refund_debt_allocations',
                $playerId,
            );
            $retainedCosmeticRestoreDebts = $this->detachByPlayer(
                'storekit_cosmetic_restore_debts',
                $playerId,
            );
            $retainedAllocations = $this->retainPurchasedSpendAllocations($playerId, $retentionPseudonym);

            // Earned-value allocations and the aggregate ledger are gameplay
            // history, not StoreKit payment/refund evidence.
            $this->executeDelete(
                "DELETE FROM coin_spend_allocations WHERE player_id = :player_id AND source = 'earned'",
                $playerId,
            );

            // A leaderboard administrator can also appear as an actor on
            // another player's retained operational history. Remove the raw
            // internal UUID from those rows without deleting the other
            // player's result or ledger.
            $this->anonymizeModerationActorReferences($playerId);

            // These history tables intentionally have restrictive or no player
            // foreign keys. Remove them explicitly before the player row so no
            // nickname, public result, proof trace, moderation trail, or earned
            // economy history survives account deletion.
            $this->executeDelete(
                'DELETE FROM leaderboard_moderation_events WHERE player_id = :player_id',
                $playerId,
            );
            $this->executeDelete(
                'DELETE FROM account_reward_resets '
                . 'WHERE player_id = :player_id',
                $playerId,
            );
            $this->executeDelete('DELETE FROM coin_ledger WHERE player_id = :player_id', $playerId);
            $this->executeDelete(
                'DELETE FROM run_trace_claims WHERE first_run_id IN '
                . '(SELECT run_id FROM run_attempts WHERE player_id = :player_id)',
                $playerId,
            );
            $this->executeDelete(
                'DELETE FROM run_proofs WHERE run_id IN '
                . '(SELECT run_id FROM run_attempts WHERE player_id = :player_id)',
                $playerId,
            );
            $this->executeDelete('DELETE FROM run_attempts WHERE player_id = :player_id', $playerId);
            $this->executeDelete('DELETE FROM completed_runs WHERE player_id = :player_id', $playerId);
            $this->executeDelete('DELETE FROM leaderboard_entries WHERE player_id = :player_id', $playerId);

            // Delete the raw appAccountToken before the player cascade. The
            // StoreKit evidence above retains only the keyed pseudonym.
            $this->executeDelete(
                'DELETE FROM player_storekit_bindings WHERE player_id = :player_id',
                $playerId,
            );

            $deletePlayer = $this->database->prepare(
                'DELETE FROM players WHERE id = :player_id'
            );
            $deletePlayer->execute(['player_id' => $playerId]);
            if ($deletePlayer->rowCount() !== 1) {
                throw new ApiException(401, 'Sign in again before deleting this account.');
            }

            $this->database->commit();
            return [
                'deleted' => true,
                'retainedStoreKitTransactions' => $retainedTransactions,
                'retainedPurchasedCoinLots' => $retainedLots,
                'retainedEntitlementSources' => $retainedEntitlements,
                'retainedPurchasedSpendAllocations' => $retainedAllocations,
                'retainedRefundDebtSettlements' => $retainedRefundDebtSettlements,
                'retainedRefundedCosmetics' => $retainedRefundedCosmetics,
                'retainedCosmeticRestoreDebts' => $retainedCosmeticRestoreDebts,
            ];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function lockPlayer(string $playerId): void
    {
        $forUpdate = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
        $statement = $this->database->prepare(
            'SELECT id FROM players WHERE id = :player_id' . $forUpdate
        );
        $statement->execute(['player_id' => $playerId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException(401, 'Sign in again before deleting this account.');
        }
    }

    private function appAccountToken(string $playerId): ?string
    {
        $statement = $this->database->prepare(
            'SELECT app_account_token FROM player_storekit_bindings '
            . 'WHERE player_id = :player_id LIMIT 1'
        );
        $statement->execute(['player_id' => $playerId]);
        $value = $statement->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function detachStoreKitTransactions(string $playerId): int
    {
        $statement = $this->database->prepare(
            'UPDATE storekit_transactions SET player_id = NULL, app_transaction_id = NULL, '
            . 'account_deleted_at = UTC_TIMESTAMP(3) WHERE player_id = :player_id'
        );
        $statement->bindValue(':player_id', $playerId);
        $statement->execute();
        return $statement->rowCount();
    }

    private function tombstoneFamilyBindings(string $playerId): void
    {
        $statement = $this->database->prepare(
            'UPDATE player_storekit_family_bindings SET player_id = NULL, '
            . 'account_deleted_at = UTC_TIMESTAMP(3) WHERE player_id = :player_id'
        );
        $statement->execute(['player_id' => $playerId]);
    }

    private function detachByPlayer(string $table, string $playerId): int
    {
        if (!in_array(
            $table,
            [
                'purchased_coin_lots',
                'player_entitlement_sources',
                'storekit_refund_cosmetics',
                'storekit_refund_debt_allocations',
                'storekit_cosmetic_restore_debts',
            ],
            true,
        )) {
            throw new InvalidArgumentException('Unsupported StoreKit retention table.');
        }
        $statement = $this->database->prepare(
            'UPDATE ' . $table . ' SET player_id = NULL WHERE player_id = :player_id'
        );
        $statement->execute(['player_id' => $playerId]);
        return $statement->rowCount();
    }

    private function hasPurchasedSpendAllocations(string $playerId): bool
    {
        $statement = $this->database->prepare(
            "SELECT 1 FROM coin_spend_allocations WHERE player_id = :player_id "
            . "AND source = 'purchased' LIMIT 1"
        );
        $statement->execute(['player_id' => $playerId]);
        return $statement->fetchColumn() !== false;
    }

    private function hasStoreKitTransactions(string $playerId): bool
    {
        $statement = $this->database->prepare(
            'SELECT 1 FROM storekit_transactions WHERE player_id = :player_id LIMIT 1'
        );
        $statement->execute(['player_id' => $playerId]);
        return $statement->fetchColumn() !== false;
    }

    private function hasEarnedRefundDebtSettlements(string $playerId): bool
    {
        $statement = $this->database->prepare(
            'SELECT 1 FROM storekit_refund_debt_allocations WHERE player_id = :player_id '
            . "AND source_type = 'earned_credit' LIMIT 1"
        );
        $statement->execute(['player_id' => $playerId]);
        return $statement->fetchColumn() !== false;
    }

    private function pseudonymizeEarnedRefundDebtSettlements(string $playerId): void
    {
        $forUpdate = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
        $statement = $this->database->prepare(
            'SELECT allocation_id, source_reference FROM storekit_refund_debt_allocations '
            . "WHERE player_id = :player_id AND source_type = 'earned_credit'" . $forUpdate
        );
        $statement->execute(['player_id' => $playerId]);
        $update = $this->database->prepare(
            'UPDATE storekit_refund_debt_allocations SET source_reference = :source_reference '
            . 'WHERE allocation_id = :allocation_id'
        );
        foreach ($statement->fetchAll() as $allocation) {
            $reference = (string) ($allocation['source_reference'] ?? '');
            $update->execute([
                'source_reference' => bin2hex(StoreKitPseudonym::spend(
                    $this->retentionHmacKey,
                    $reference,
                )),
                'allocation_id' => $allocation['allocation_id'],
            ]);
        }
    }

    private function retainPurchasedSpendAllocations(string $playerId, ?string $pseudonym): int
    {
        if ($pseudonym === null) {
            return 0;
        }
        $statement = $this->database->prepare(
            "UPDATE coin_spend_allocations SET player_id = NULL, spend_event_id = NULL, "
            . "spend_reference_pseudonym = :pseudonym "
            . "WHERE player_id = :player_id AND source = 'purchased'"
        );
        $statement->bindValue(':pseudonym', $pseudonym, PDO::PARAM_LOB);
        $statement->bindValue(':player_id', $playerId);
        $statement->execute();
        return $statement->rowCount();
    }

    private function anonymizeModerationActorReferences(string $playerId): void
    {
        $actor = 'admin:' . $playerId;
        foreach (['leaderboard_entries', 'completed_runs'] as $table) {
            $statement = $this->database->prepare(
                'UPDATE ' . $table . " SET moderated_by = 'deleted-account' "
                . 'WHERE moderated_by = :actor'
            );
            $statement->execute(['actor' => $actor]);
        }

        $moderation = $this->database->prepare(
            "UPDATE leaderboard_moderation_events SET actor = 'deleted-account' "
            . 'WHERE actor = :actor'
        );
        $moderation->execute(['actor' => $actor]);

        $ledger = $this->database->prepare(
            'UPDATE coin_ledger SET actor = NULL WHERE actor = :actor'
        );
        $ledger->execute(['actor' => $actor]);

        $rewardResets = $this->database->prepare(
            'UPDATE account_reward_resets SET actor_player_id = NULL '
            . 'WHERE actor_player_id = :player_id'
        );
        $rewardResets->execute(['player_id' => $playerId]);
    }

    /** @param array<string, scalar|null> $extraParameters */
    private function executeDelete(
        string $sql,
        string $playerId,
        array $extraParameters = [],
    ): void {
        $statement = $this->database->prepare($sql);
        $statement->execute(['player_id' => $playerId, ...$extraParameters]);
    }
}
