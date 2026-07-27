-- One-time internal-alpha clean slate requested by the product owner.
--
-- This removes every live PimPoPom profile and all player-visible gameplay,
-- leaderboard, achievement, cosmetic, wallet, session, identity, Game Center,
-- and purchase-entitlement state. It deliberately retains detached StoreKit
-- transaction, notification, observation, refund, and allocation evidence so
-- an old Apple transaction cannot be replayed for a second credit and later
-- refunds/reversals can still be reconciled.
--
-- Pause the Game Center publisher and StoreKit reconciler before applying this
-- migration. Apple Game Center/Sandbox test history is a separate Apple-side
-- data store and is not changed by this SQL migration.

SET @speedytapper_reset_gc_prerelease_lock = GET_LOCK(
    'speedytapper-game-center-publish-prerelease',
    120
);
SET @speedytapper_reset_gc_prerelease_lock_sql = IF(
    @speedytapper_reset_gc_prerelease_lock = 1,
    'DO 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Could not fence the prerelease Game Center publisher'''
);
PREPARE speedytapper_reset_gc_prerelease_lock_statement
    FROM @speedytapper_reset_gc_prerelease_lock_sql;
EXECUTE speedytapper_reset_gc_prerelease_lock_statement;
DEALLOCATE PREPARE speedytapper_reset_gc_prerelease_lock_statement;

SET @speedytapper_reset_gc_production_lock = GET_LOCK(
    'speedytapper-game-center-publish-production',
    120
);
SET @speedytapper_reset_gc_production_lock_sql = IF(
    @speedytapper_reset_gc_production_lock = 1,
    'DO 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Could not fence the production Game Center publisher'''
);
PREPARE speedytapper_reset_gc_production_lock_statement
    FROM @speedytapper_reset_gc_production_lock_sql;
EXECUTE speedytapper_reset_gc_production_lock_statement;
DEALLOCATE PREPARE speedytapper_reset_gc_production_lock_statement;

SET @speedytapper_reset_storekit_sandbox_lock = GET_LOCK(
    'speedytapper-storekit-reconcile-sandbox',
    120
);
SET @speedytapper_reset_storekit_sandbox_lock_sql = IF(
    @speedytapper_reset_storekit_sandbox_lock = 1,
    'DO 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Could not fence the Sandbox StoreKit reconciler'''
);
PREPARE speedytapper_reset_storekit_sandbox_lock_statement
    FROM @speedytapper_reset_storekit_sandbox_lock_sql;
EXECUTE speedytapper_reset_storekit_sandbox_lock_statement;
DEALLOCATE PREPARE speedytapper_reset_storekit_sandbox_lock_statement;

SET @speedytapper_reset_storekit_production_lock = GET_LOCK(
    'speedytapper-storekit-reconcile-production',
    120
);
SET @speedytapper_reset_storekit_production_lock_sql = IF(
    @speedytapper_reset_storekit_production_lock = 1,
    'DO 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Could not fence the Production StoreKit reconciler'''
);
PREPARE speedytapper_reset_storekit_production_lock_statement
    FROM @speedytapper_reset_storekit_production_lock_sql;
EXECUTE speedytapper_reset_storekit_production_lock_statement;
DEALLOCATE PREPARE speedytapper_reset_storekit_production_lock_statement;

START TRANSACTION;

-- Claim the destructive data migration inside the same transaction as the
-- reset. If PHP commits this transaction but crashes before MigrationRunner
-- records the filename, a rerun observes the marker and performs no DML.
INSERT IGNORE INTO migration_data_markers (marker)
VALUES ('020-internal-alpha-player-data-reset-20260727-v1');
SET @speedytapper_internal_alpha_reset_claimed = ROW_COUNT();

-- Replace the last player-correlatable references inside retained StoreKit
-- evidence with the already-keyed account pseudonym stored on the signed
-- transaction. Do this before detaching transaction/player relationships.
UPDATE storekit_refund_debt_allocations AS allocation
INNER JOIN storekit_transactions AS transaction_record
    ON transaction_record.transaction_id = allocation.refund_transaction_id
SET allocation.source_reference = LOWER(HEX(transaction_record.account_token_pseudonym))
WHERE @speedytapper_internal_alpha_reset_claimed = 1
  AND allocation.source_type = 'earned_credit';

UPDATE coin_spend_allocations AS allocation
INNER JOIN purchased_coin_lots AS lot
    ON lot.transaction_id = allocation.lot_transaction_id
INNER JOIN storekit_transactions AS transaction_record
    ON transaction_record.transaction_id = lot.transaction_id
SET allocation.player_id = NULL,
    allocation.spend_event_id = NULL,
    allocation.spend_reference_pseudonym = transaction_record.account_token_pseudonym
WHERE allocation.source = 'purchased'
  AND @speedytapper_internal_alpha_reset_claimed = 1;

DELETE FROM coin_spend_allocations
WHERE source = 'earned'
  AND @speedytapper_internal_alpha_reset_claimed = 1;

-- Preserve immutable paid-value evidence while removing every live account
-- association. account_deleted_at prevents an old direct purchase from being
-- attached to a newly-created profile.
UPDATE storekit_transactions
SET player_id = NULL,
    app_transaction_id = NULL,
    account_deleted_at = COALESCE(account_deleted_at, UTC_TIMESTAMP(3))
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

UPDATE player_storekit_family_bindings
SET player_id = NULL,
    account_deleted_at = COALESCE(account_deleted_at, UTC_TIMESTAMP(3))
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

UPDATE purchased_coin_lots
SET player_id = NULL
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

UPDATE player_entitlement_sources
SET player_id = NULL
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

UPDATE storekit_refund_cosmetics
SET player_id = NULL
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

UPDATE storekit_refund_debt_allocations
SET player_id = NULL
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

UPDATE storekit_cosmetic_restore_debts
SET player_id = NULL
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

-- Remove publication work before deleting bindings/profiles. The publishers
-- are paused operationally so no claimed Apple request can escape this reset.
DELETE FROM game_center_publication_outbox
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM player_game_center_bindings
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM game_center_assertion_uses
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

-- Remove restrictive audit/proof rows before their parent rows.
DELETE FROM account_reward_resets
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM leaderboard_moderation_events
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM run_trace_claims
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM run_proofs
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM run_attempts
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM completed_runs
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM leaderboard_entries
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

-- Purchased allocations retained above no longer reference these ledger rows.
DELETE FROM coin_ledger
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

-- Delete explicit live account state. Most of these also cascade from players;
-- spelling them out makes the intended clean-slate scope auditable.
DELETE FROM player_achievements
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM player_pet_selection
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM player_pets
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM player_theme_selection
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM player_themes
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM player_roles
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM player_sessions
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM player_apple_authorizations
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM player_identities
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM player_storekit_bindings
WHERE @speedytapper_internal_alpha_reset_claimed = 1;
DELETE FROM players
WHERE @speedytapper_internal_alpha_reset_claimed = 1;

COMMIT;

DO RELEASE_LOCK('speedytapper-storekit-reconcile-production');
DO RELEASE_LOCK('speedytapper-storekit-reconcile-sandbox');
DO RELEASE_LOCK('speedytapper-game-center-publish-production');
DO RELEASE_LOCK('speedytapper-game-center-publish-prerelease');
