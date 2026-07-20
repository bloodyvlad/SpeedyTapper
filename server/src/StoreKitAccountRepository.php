<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class StoreKitAccountRepository
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $retentionHmacKey,
    )
    {
    }

    public function appAccountToken(string $playerId): string
    {
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $player = $this->database->prepare(
                'SELECT id FROM players WHERE id = :player_id FOR UPDATE'
            );
            $player->execute(['player_id' => $playerId]);
            if ($player->fetchColumn() === false) {
                throw new ApiException(401, 'Sign in to continue.');
            }

            $existing = $this->binding($playerId, true);
            if ($existing === null) {
                $token = Uuid::v4();
                try {
                    $insert = $this->database->prepare(
                        'INSERT INTO player_storekit_bindings (player_id, app_account_token) '
                        . 'VALUES (:player_id, :app_account_token)'
                    );
                    $insert->execute([
                        'player_id' => $playerId,
                        'app_account_token' => $token,
                    ]);
                    $existing = $token;
                } catch (PDOException $error) {
                    if ($error->getCode() !== '23000') {
                        throw $error;
                    }
                    $existing = $this->binding($playerId, true);
                    if ($existing === null) {
                        throw $error;
                    }
                }
            }
            if ($ownsTransaction) {
                $this->database->commit();
            }
            return $existing;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function binding(string $playerId, bool $forUpdate = false): ?string
    {
        $statement = $this->database->prepare(
            'SELECT app_account_token FROM player_storekit_bindings '
            . 'WHERE player_id = :player_id LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['player_id' => $playerId]);
        $token = $statement->fetchColumn();
        return is_string($token) && Uuid::isValidV4($token) ? $token : null;
    }

    public function playerIdForToken(string $appAccountToken, bool $forUpdate = false): ?string
    {
        $token = strtolower(trim($appAccountToken));
        if (!Uuid::isValidV4($token)) return null;
        $statement = $this->database->prepare(
            'SELECT player_id FROM player_storekit_bindings '
            . 'WHERE app_account_token = :app_account_token LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['app_account_token' => $token]);
        $playerId = $statement->fetchColumn();
        return is_string($playerId) && Uuid::isValidV4($playerId) ? $playerId : null;
    }

    /**
     * Bind Apple's signed per-family-member appTransactionId to exactly one
     * PimPoPom profile. Deleted bindings remain tombstoned and cannot be
     * transferred by replaying a Family Sharing JWS on another profile.
     */
    public function bindFamilyBeneficiary(
        string $environment,
        string $appTransactionId,
        string $playerId,
    ): void
    {
        if (!$this->database->inTransaction()) {
            throw new \LogicException('Family Sharing binding requires an active transaction.');
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $appTransactionId) !== 1) {
            throw new ApiException(400, 'The signed Apple app transaction is invalid.');
        }
        $player = $this->database->prepare(
            'SELECT id FROM players WHERE id = :player_id FOR UPDATE'
        );
        $player->execute(['player_id' => $playerId]);
        if ($player->fetchColumn() === false) {
            throw new ApiException(401, 'Sign in to continue.');
        }

        $this->requireEnvironment($environment);
        $pseudonym = $this->familyPseudonym($appTransactionId);
        $statement = $this->database->prepare(
            'SELECT player_id, account_deleted_at FROM player_storekit_family_bindings '
            . 'WHERE environment = :environment '
            . 'AND app_transaction_pseudonym = :app_transaction_pseudonym FOR UPDATE'
        );
        $statement->bindValue(':environment', $environment);
        $statement->bindValue(':app_transaction_pseudonym', $pseudonym, PDO::PARAM_LOB);
        $statement->execute();
        $existing = $statement->fetch();
        if (!is_array($existing)) {
            $insert = $this->database->prepare(
                'INSERT INTO player_storekit_family_bindings '
                . '(environment, app_transaction_pseudonym, player_id) '
                . 'VALUES (:environment, :app_transaction_pseudonym, :player_id)'
            );
            $insert->bindValue(':environment', $environment);
            $insert->bindValue(':app_transaction_pseudonym', $pseudonym, PDO::PARAM_LOB);
            $insert->bindValue(':player_id', $playerId);
            $insert->execute();
            return;
        }
        if ($existing['account_deleted_at'] !== null || !is_string($existing['player_id'])) {
            throw new ApiException(409, 'This Family Sharing identity belonged to a deleted account.');
        }
        if (!hash_equals($existing['player_id'], $playerId)) {
            throw new ApiException(409, 'This Family Sharing entitlement belongs to another PimPoPom profile.');
        }
    }

    public function playerIdForFamilyAppTransaction(
        string $environment,
        string $appTransactionId,
        bool $forUpdate = false,
    ): ?string
    {
        $this->requireEnvironment($environment);
        $pseudonym = $this->familyPseudonym($appTransactionId);
        $statement = $this->database->prepare(
            'SELECT player_id FROM player_storekit_family_bindings '
            . 'WHERE environment = :environment '
            . 'AND app_transaction_pseudonym = :app_transaction_pseudonym '
            . 'AND account_deleted_at IS NULL LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->bindValue(':environment', $environment);
        $statement->bindValue(':app_transaction_pseudonym', $pseudonym, PDO::PARAM_LOB);
        $statement->execute();
        $playerId = $statement->fetchColumn();
        return is_string($playerId) && Uuid::isValidV4($playerId) ? $playerId : null;
    }

    public function familyPseudonym(string $appTransactionId): string
    {
        if (strlen($this->retentionHmacKey) < 32) {
            throw new ApiException(503, 'StoreKit retention configuration is incomplete.');
        }
        return StoreKitPseudonym::appTransaction($this->retentionHmacKey, $appTransactionId);
    }

    private function requireEnvironment(string $environment): void
    {
        if (!in_array($environment, ['Sandbox', 'Production'], true)) {
            throw new ApiException(400, 'The signed App Store environment is invalid.');
        }
    }

    /** @return array{earned: int, purchased: int, earnedDebt: int, refundDebt: int, total: int} */
    public function walletSummary(string $playerId): array
    {
        $statement = $this->database->prepare(
            'SELECT earned_coins, purchased_coins, earned_coin_debt, refund_coin_debt '
            . 'FROM players WHERE id = :player_id LIMIT 1'
        );
        $statement->execute(['player_id' => $playerId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(401, 'Sign in to continue.');
        }
        $wallet = CoinEconomy::summary(
            (int) $row['earned_coins'],
            (int) $row['purchased_coins'],
            (int) $row['earned_coin_debt'],
            (int) $row['refund_coin_debt'],
        );
        return [
            'earned' => $wallet['earnedCoins'],
            'purchased' => $wallet['purchasedCoins'],
            'earnedDebt' => $wallet['earnedDebt'],
            'refundDebt' => $wallet['refundDebt'],
            'total' => $wallet['coins'],
        ];
    }

    public function hasEntitlement(string $playerId, string $capability): bool
    {
        $statement = $this->database->prepare(
            'SELECT 1 FROM player_entitlement_sources '
            . 'WHERE player_id = :player_id AND capability = :capability AND active = 1 LIMIT 1'
        );
        $statement->execute([
            'player_id' => $playerId,
            'capability' => $capability,
        ]);
        return $statement->fetchColumn() !== false;
    }

    /** @return array{wallet: array, adFree: bool, storeKit: array{appAccountToken: string, bindingStatus: string}} */
    public function state(string $playerId): array
    {
        return [
            'wallet' => $this->walletSummary($playerId),
            'adFree' => $this->hasEntitlement($playerId, 'ad_free'),
            'storeKit' => [
                'appAccountToken' => $this->appAccountToken($playerId),
                'bindingStatus' => 'bound',
            ],
        ];
    }
}
