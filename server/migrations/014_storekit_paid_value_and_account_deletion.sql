SET @speedytapper_paid_wallet_columns = (
    SELECT CONCAT_WS(', ',
        IF(SUM(COLUMN_NAME = 'earned_coins') = 0,
            'ADD COLUMN earned_coins BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER coins', NULL),
        IF(SUM(COLUMN_NAME = 'purchased_coins') = 0,
            'ADD COLUMN purchased_coins BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER earned_coins', NULL),
        IF(SUM(COLUMN_NAME = 'earned_coin_debt') = 0,
            'ADD COLUMN earned_coin_debt BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER coin_debt', NULL),
        IF(SUM(COLUMN_NAME = 'refund_coin_debt') = 0,
            'ADD COLUMN refund_coin_debt BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER earned_coin_debt', NULL)
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
);

SET @speedytapper_paid_wallet_sql = IF(
    @speedytapper_paid_wallet_columns IS NULL OR @speedytapper_paid_wallet_columns = '',
    'DO 1',
    CONCAT('ALTER TABLE players ', @speedytapper_paid_wallet_columns)
);
PREPARE speedytapper_paid_wallet_statement FROM @speedytapper_paid_wallet_sql;
EXECUTE speedytapper_paid_wallet_statement;
DEALLOCATE PREPARE speedytapper_paid_wallet_statement;

SET @speedytapper_old_wallet_check_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND CONSTRAINT_NAME = 'players_wallet_or_debt_not_both'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @speedytapper_drop_old_wallet_check_sql = IF(
    @speedytapper_old_wallet_check_exists = 0,
    'DO 1',
    IF(
        LOCATE('MariaDB', VERSION()) > 0,
        'ALTER TABLE players DROP CONSTRAINT players_wallet_or_debt_not_both',
        'ALTER TABLE players DROP CHECK players_wallet_or_debt_not_both'
    )
);
PREPARE speedytapper_drop_old_wallet_check_statement FROM @speedytapper_drop_old_wallet_check_sql;
EXECUTE speedytapper_drop_old_wallet_check_statement;
DEALLOCATE PREPARE speedytapper_drop_old_wallet_check_statement;

ALTER TABLE coin_ledger
    MODIFY COLUMN event_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

-- Account deletion must not erase a different player's immutable reward-reset
-- audit merely because the deleted profile acted as administrator. Detach the
-- actor identity while retaining the target player's operational evidence.
SET @speedytapper_reward_reset_actor_foreign_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'account_reward_resets'
      AND CONSTRAINT_NAME = 'account_reward_resets_actor_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_drop_reward_reset_actor_foreign_sql = IF(
    @speedytapper_reward_reset_actor_foreign_exists = 0,
    'DO 1',
    'ALTER TABLE account_reward_resets DROP FOREIGN KEY account_reward_resets_actor_foreign'
);
PREPARE speedytapper_drop_reward_reset_actor_foreign_statement
    FROM @speedytapper_drop_reward_reset_actor_foreign_sql;
EXECUTE speedytapper_drop_reward_reset_actor_foreign_statement;
DEALLOCATE PREPARE speedytapper_drop_reward_reset_actor_foreign_statement;

ALTER TABLE account_reward_resets
    MODIFY COLUMN actor_player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL;

SET @speedytapper_reward_reset_actor_foreign_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'account_reward_resets'
      AND CONSTRAINT_NAME = 'account_reward_resets_actor_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_add_reward_reset_actor_foreign_sql = IF(
    @speedytapper_reward_reset_actor_foreign_exists = 0,
    'ALTER TABLE account_reward_resets ADD CONSTRAINT account_reward_resets_actor_foreign FOREIGN KEY (actor_player_id) REFERENCES players (id) ON DELETE SET NULL',
    'DO 1'
);
PREPARE speedytapper_add_reward_reset_actor_foreign_statement
    FROM @speedytapper_add_reward_reset_actor_foreign_sql;
EXECUTE speedytapper_add_reward_reset_actor_foreign_statement;
DEALLOCATE PREPARE speedytapper_add_reward_reset_actor_foreign_statement;

SET @speedytapper_ledger_provenance_columns = (
    SELECT CONCAT_WS(', ',
        IF(SUM(COLUMN_NAME = 'earned_delta') = 0,
            'ADD COLUMN earned_delta BIGINT NOT NULL DEFAULT 0 AFTER coin_delta', NULL),
        IF(SUM(COLUMN_NAME = 'purchased_delta') = 0,
            'ADD COLUMN purchased_delta BIGINT NOT NULL DEFAULT 0 AFTER earned_delta', NULL),
        IF(SUM(COLUMN_NAME = 'earned_balance_after') = 0,
            'ADD COLUMN earned_balance_after BIGINT UNSIGNED NULL AFTER coin_balance_after', NULL),
        IF(SUM(COLUMN_NAME = 'purchased_balance_after') = 0,
            'ADD COLUMN purchased_balance_after BIGINT UNSIGNED NULL AFTER earned_balance_after', NULL),
        IF(SUM(COLUMN_NAME = 'earned_debt_after') = 0,
            'ADD COLUMN earned_debt_after BIGINT UNSIGNED NULL AFTER coin_debt_after', NULL),
        IF(SUM(COLUMN_NAME = 'refund_debt_after') = 0,
            'ADD COLUMN refund_debt_after BIGINT UNSIGNED NULL AFTER earned_debt_after', NULL)
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'coin_ledger'
);
SET @speedytapper_ledger_provenance_sql = IF(
    @speedytapper_ledger_provenance_columns IS NULL OR @speedytapper_ledger_provenance_columns = '',
    'DO 1',
    CONCAT('ALTER TABLE coin_ledger ', @speedytapper_ledger_provenance_columns)
);
PREPARE speedytapper_ledger_provenance_statement FROM @speedytapper_ledger_provenance_sql;
EXECUTE speedytapper_ledger_provenance_statement;
DEALLOCATE PREPARE speedytapper_ledger_provenance_statement;

CREATE TABLE IF NOT EXISTS player_storekit_bindings (
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    app_account_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (player_id),
    UNIQUE KEY player_storekit_token_unique (app_account_token),
    CONSTRAINT player_storekit_binding_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_storekit_family_bindings (
    app_transaction_pseudonym BINARY(32) NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    account_deleted_at TIMESTAMP(3) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (app_transaction_pseudonym),
    KEY player_storekit_family_player_index (player_id, account_deleted_at),
    CONSTRAINT player_storekit_family_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storekit_transactions (
    transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    app_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    app_transaction_pseudonym BINARY(32) NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    account_token_pseudonym BINARY(32) NOT NULL,
    product_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_type ENUM('consumable','non_consumable') NOT NULL,
    ownership_type ENUM('PURCHASED','FAMILY_SHARED') NOT NULL,
    environment ENUM('Sandbox','Production') NOT NULL,
    bundle_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    app_apple_id BIGINT UNSIGNED NULL,
    signed_quantity INT UNSIGNED NOT NULL,
    purchase_date_ms BIGINT UNSIGNED NOT NULL,
    signed_date_ms BIGINT UNSIGNED NOT NULL,
    lifecycle_signed_date_ms BIGINT UNSIGNED NOT NULL,
    revocation_date_ms BIGINT UNSIGNED NULL,
    revocation_reason SMALLINT UNSIGNED NULL,
    revocation_percentage INT UNSIGNED NULL,
    status ENUM('active','refunded','revoked','reinstated') NOT NULL DEFAULT 'active',
    credited_coins BIGINT UNSIGNED NOT NULL DEFAULT 0,
    refund_debt_created BIGINT UNSIGNED NOT NULL DEFAULT 0,
    refund_debt_outstanding BIGINT UNSIGNED NOT NULL DEFAULT 0,
    base_refund_debt_outstanding BIGINT UNSIGNED NOT NULL DEFAULT 0,
    transition_version INT UNSIGNED NOT NULL DEFAULT 0,
    account_deleted_at TIMESTAMP(3) NULL,
    payload_hash BINARY(32) NOT NULL,
    verified_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (transaction_id),
    KEY storekit_original_transaction_index (original_transaction_id, transaction_id),
    KEY storekit_player_time_index (player_id, verified_at, transaction_id),
    KEY storekit_account_pseudonym_index (account_token_pseudonym, transaction_id),
    KEY storekit_environment_transaction_index (environment, transaction_id),
    CONSTRAINT storekit_transaction_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE SET NULL,
    CONSTRAINT storekit_transaction_quantity_positive CHECK (signed_quantity > 0),
    CONSTRAINT storekit_transaction_revocation_percentage_range CHECK (
        revocation_percentage IS NULL OR revocation_percentage <= 100000
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @speedytapper_storekit_environment_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_transactions'
      AND INDEX_NAME = 'storekit_environment_transaction_index'
);
SET @speedytapper_storekit_environment_index_sql = IF(
    @speedytapper_storekit_environment_index_exists = 0,
    'ALTER TABLE storekit_transactions ADD KEY storekit_environment_transaction_index (environment, transaction_id)',
    'DO 1'
);
PREPARE speedytapper_storekit_environment_index_statement
    FROM @speedytapper_storekit_environment_index_sql;
EXECUTE speedytapper_storekit_environment_index_statement;
DEALLOCATE PREPARE speedytapper_storekit_environment_index_statement;

CREATE TABLE IF NOT EXISTS purchased_coin_lots (
    transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    gross_coins BIGINT UNSIGNED NOT NULL,
    available_coins BIGINT UNSIGNED NOT NULL,
    spent_coins BIGINT UNSIGNED NOT NULL DEFAULT 0,
    refund_debt_settled_coins BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reversed_coins BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('uncredited','active','refunded','reinstated') NOT NULL DEFAULT 'active',
    credited_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (transaction_id),
    KEY purchased_coin_lots_player_fifo_index (player_id, status, credited_at, transaction_id),
    CONSTRAINT purchased_coin_lot_transaction_foreign
        FOREIGN KEY (transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT,
    CONSTRAINT purchased_coin_lot_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storekit_transaction_observations (
    observation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observed_state ENUM('active','refunded','revoked','reinstated') NOT NULL,
    signed_date_ms BIGINT UNSIGNED NOT NULL,
    revocation_date_ms BIGINT UNSIGNED NULL,
    payload_hash BINARY(32) NOT NULL,
    observed_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (observation_id),
    UNIQUE KEY storekit_transaction_observation_unique (transaction_id, payload_hash),
    KEY storekit_transaction_observation_time_index (transaction_id, observed_at),
    CONSTRAINT storekit_transaction_observation_foreign
        FOREIGN KEY (transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_entitlement_sources (
    source_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    capability VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    granted_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    revoked_at TIMESTAMP(3) NULL,
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (source_id),
    UNIQUE KEY player_entitlement_transaction_unique (player_id, source_transaction_id, capability),
    KEY player_entitlement_active_index (player_id, capability, active),
    CONSTRAINT player_entitlement_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE SET NULL,
    CONSTRAINT player_entitlement_transaction_foreign
        FOREIGN KEY (source_transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storekit_refund_debt_allocations (
    allocation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_type ENUM('earned_credit','storekit_purchase') NOT NULL,
    source_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_economy_generation INT UNSIGNED NULL,
    source_purchase_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    refund_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cosmetic_restore_debt_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    amount BIGINT UNSIGNED NOT NULL,
    released_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_revoked_at TIMESTAMP(3) NULL,
    released_at TIMESTAMP(3) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (allocation_id),
    KEY storekit_refund_debt_refund_index (refund_transaction_id, released_at, created_at),
    CONSTRAINT storekit_refund_debt_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE SET NULL,
    CONSTRAINT storekit_refund_debt_source_foreign
        FOREIGN KEY (source_purchase_transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT,
    CONSTRAINT storekit_refund_debt_target_foreign
        FOREIGN KEY (refund_transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT,
    CONSTRAINT storekit_refund_debt_amount_positive CHECK (amount > 0),
    CONSTRAINT storekit_refund_debt_release_range CHECK (released_amount <= amount),
    CONSTRAINT storekit_refund_debt_source_consistency CHECK (
        (source_type = 'earned_credit' AND source_purchase_transaction_id IS NULL)
        OR (source_type = 'storekit_purchase' AND source_purchase_transaction_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coin_spend_allocations (
    allocation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    spend_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source ENUM('earned','purchased') NOT NULL,
    lot_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    amount BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    spend_reference_pseudonym BINARY(32) NOT NULL,
    released_at TIMESTAMP(3) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (allocation_id),
    KEY coin_spend_event_index (spend_event_id, source),
    KEY coin_spend_player_time_index (player_id, created_at),
    KEY coin_spend_lot_index (lot_transaction_id, created_at),
    CONSTRAINT coin_spend_event_foreign
        FOREIGN KEY (spend_event_id) REFERENCES coin_ledger (event_id) ON DELETE SET NULL,
    CONSTRAINT coin_spend_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE SET NULL,
    CONSTRAINT coin_spend_lot_foreign
        FOREIGN KEY (lot_transaction_id) REFERENCES purchased_coin_lots (transaction_id) ON DELETE RESTRICT,
    CONSTRAINT coin_spend_amount_positive CHECK (amount > 0),
    CONSTRAINT coin_spend_source_lot_consistency CHECK (
        (source = 'earned' AND lot_transaction_id IS NULL)
        OR (source = 'purchased' AND lot_transaction_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @speedytapper_allocation_release_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'coin_spend_allocations'
      AND COLUMN_NAME = 'released_at'
);
SET @speedytapper_allocation_release_sql = IF(
    @speedytapper_allocation_release_exists = 0,
    'ALTER TABLE coin_spend_allocations ADD COLUMN released_at TIMESTAMP(3) NULL AFTER spend_reference_pseudonym',
    'DO 1'
);
PREPARE speedytapper_allocation_release_statement FROM @speedytapper_allocation_release_sql;
EXECUTE speedytapper_allocation_release_statement;
DEALLOCATE PREPARE speedytapper_allocation_release_statement;

CREATE TABLE IF NOT EXISTS storekit_notifications (
    notification_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    notification_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subtype VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    environment ENUM('Sandbox','Production') NOT NULL,
    signed_date_ms BIGINT UNSIGNED NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    processing_status ENUM('processed','ignored') NOT NULL,
    processed_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (notification_uuid),
    KEY storekit_notification_transaction_index (transaction_id, processed_at),
    CONSTRAINT storekit_notification_transaction_foreign
        FOREIGN KEY (transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @speedytapper_pet_purchase_event_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_pets' AND COLUMN_NAME = 'purchase_event_id'
);
SET @speedytapper_pet_purchase_event_sql = IF(
    @speedytapper_pet_purchase_event_exists = 0,
    'ALTER TABLE player_pets ADD COLUMN purchase_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER acquisition_source, ADD KEY player_pets_purchase_event_index (purchase_event_id)',
    'DO 1'
);
PREPARE speedytapper_pet_purchase_event_statement FROM @speedytapper_pet_purchase_event_sql;
EXECUTE speedytapper_pet_purchase_event_statement;
DEALLOCATE PREPARE speedytapper_pet_purchase_event_statement;

SET @speedytapper_theme_purchase_event_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_themes' AND COLUMN_NAME = 'purchase_event_id'
);
SET @speedytapper_theme_purchase_event_sql = IF(
    @speedytapper_theme_purchase_event_exists = 0,
    'ALTER TABLE player_themes ADD COLUMN purchase_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER price_paid, ADD KEY player_themes_purchase_event_index (purchase_event_id)',
    'DO 1'
);
PREPARE speedytapper_theme_purchase_event_statement FROM @speedytapper_theme_purchase_event_sql;
EXECUTE speedytapper_theme_purchase_event_statement;
DEALLOCATE PREPARE speedytapper_theme_purchase_event_statement;

CREATE TABLE IF NOT EXISTS storekit_reconciliation_state (
    environment ENUM('Sandbox','Production') NOT NULL,
    last_notification_check_at TIMESTAMP(3) NULL,
    last_transaction_check_at TIMESTAMP(3) NULL,
    last_transaction_cursor VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_error VARCHAR(500) NULL,
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storekit_refund_cosmetics (
    revocation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    refund_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    purchase_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    refund_cycle INT UNSIGNED NOT NULL,
    item_type ENUM('pet','theme') NOT NULL,
    item_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    price_paid BIGINT UNSIGNED NOT NULL,
    restored_at TIMESTAMP(3) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (revocation_id),
    UNIQUE KEY storekit_refund_cosmetic_unique (
        refund_transaction_id, refund_cycle, purchase_event_id, item_type, item_id
    ),
    KEY storekit_refund_cosmetic_player_index (player_id, restored_at, created_at),
    CONSTRAINT storekit_refund_cosmetic_transaction_foreign
        FOREIGN KEY (refund_transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT,
    CONSTRAINT storekit_refund_cosmetic_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE SET NULL,
    CONSTRAINT storekit_refund_cosmetic_price_positive CHECK (price_paid > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storekit_cosmetic_restore_debts (
    debt_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    refund_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    purchase_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_type ENUM('pet','theme') NOT NULL,
    item_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    settled_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    released_at TIMESTAMP(3) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (debt_id),
    UNIQUE KEY storekit_cosmetic_restore_debt_unique (refund_transaction_id, purchase_event_id),
    KEY storekit_cosmetic_restore_debt_player_index (player_id, released_at, created_at),
    CONSTRAINT storekit_cosmetic_restore_debt_transaction_foreign
        FOREIGN KEY (refund_transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT,
    CONSTRAINT storekit_cosmetic_restore_debt_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE SET NULL,
    CONSTRAINT storekit_cosmetic_restore_debt_amount_positive CHECK (amount > 0),
    CONSTRAINT storekit_cosmetic_restore_debt_settlement_range CHECK (settled_amount <= amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @speedytapper_refund_debt_component_foreign_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_refund_debt_allocations'
      AND CONSTRAINT_NAME = 'storekit_refund_debt_component_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_refund_debt_component_foreign_sql = IF(
    @speedytapper_refund_debt_component_foreign_exists = 0,
    'ALTER TABLE storekit_refund_debt_allocations ADD CONSTRAINT storekit_refund_debt_component_foreign FOREIGN KEY (cosmetic_restore_debt_id) REFERENCES storekit_cosmetic_restore_debts (debt_id) ON DELETE RESTRICT',
    'DO 1'
);
PREPARE speedytapper_refund_debt_component_foreign_statement FROM @speedytapper_refund_debt_component_foreign_sql;
EXECUTE speedytapper_refund_debt_component_foreign_statement;
DEALLOCATE PREPARE speedytapper_refund_debt_component_foreign_statement;

INSERT IGNORE INTO coin_spend_allocations (
    allocation_id,
    spend_event_id,
    player_id,
    source,
    lot_transaction_id,
    amount,
    purpose,
    spend_reference_pseudonym,
    created_at
)
SELECT
    UUID(),
    ledger.event_id,
    ledger.player_id,
    'earned',
    NULL,
    ABS(ledger.coin_delta),
    ledger.event_type,
    UNHEX(SHA2(ledger.event_key, 256)),
    ledger.created_at
FROM coin_ledger ledger
LEFT JOIN coin_spend_allocations allocation ON allocation.spend_event_id = ledger.event_id
WHERE ledger.event_type IN ('pet_purchase', 'theme_purchase')
  AND ledger.coin_delta < 0
  AND allocation.allocation_id IS NULL;

CREATE TABLE IF NOT EXISTS migration_data_markers (
    marker VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (marker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
INSERT IGNORE INTO migration_data_markers (marker)
VALUES ('014-storekit-earned-wallet-backfill-v1');
SET @speedytapper_storekit_backfill_claimed = ROW_COUNT();

UPDATE players
SET earned_coins = coins,
    purchased_coins = 0,
    earned_coin_debt = coin_debt,
    refund_coin_debt = 0
WHERE @speedytapper_storekit_backfill_claimed = 1;

UPDATE coin_ledger
SET earned_delta = coin_delta,
    purchased_delta = 0,
    earned_balance_after = coin_balance_after,
    purchased_balance_after = CASE WHEN coin_balance_after IS NULL THEN NULL ELSE 0 END,
    earned_debt_after = coin_debt_after,
    refund_debt_after = CASE WHEN coin_debt_after IS NULL THEN NULL ELSE 0 END
WHERE @speedytapper_storekit_backfill_claimed = 1;
COMMIT;

SET @speedytapper_wallet_provenance_check_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'players'
      AND CONSTRAINT_NAME = 'players_paid_wallet_consistent' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @speedytapper_wallet_provenance_check_sql = IF(
    @speedytapper_wallet_provenance_check_exists = 0,
    'ALTER TABLE players ADD CONSTRAINT players_paid_wallet_consistent CHECK (coins = earned_coins + purchased_coins AND coin_debt = earned_coin_debt + refund_coin_debt AND NOT (earned_coins > 0 AND earned_coin_debt > 0))',
    'DO 1'
);
PREPARE speedytapper_wallet_provenance_check_statement FROM @speedytapper_wallet_provenance_check_sql;
EXECUTE speedytapper_wallet_provenance_check_statement;
DEALLOCATE PREPARE speedytapper_wallet_provenance_check_statement;

SET @speedytapper_lot_conservation_check_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchased_coin_lots'
      AND CONSTRAINT_NAME = 'purchased_coin_lot_conservation' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @speedytapper_lot_conservation_check_sql = IF(
    @speedytapper_lot_conservation_check_exists = 0,
    'ALTER TABLE purchased_coin_lots ADD CONSTRAINT purchased_coin_lot_conservation CHECK (gross_coins = available_coins + spent_coins + refund_debt_settled_coins + reversed_coins)',
    'DO 1'
);
PREPARE speedytapper_lot_conservation_check_statement FROM @speedytapper_lot_conservation_check_sql;
EXECUTE speedytapper_lot_conservation_check_statement;
DEALLOCATE PREPARE speedytapper_lot_conservation_check_statement;

SET @speedytapper_transaction_debt_check_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storekit_transactions'
      AND CONSTRAINT_NAME = 'storekit_transaction_debt_consistent' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @speedytapper_transaction_debt_check_sql = IF(
    @speedytapper_transaction_debt_check_exists = 0,
    'ALTER TABLE storekit_transactions ADD CONSTRAINT storekit_transaction_debt_consistent CHECK (base_refund_debt_outstanding <= refund_debt_outstanding AND refund_debt_outstanding <= refund_debt_created)',
    'DO 1'
);
PREPARE speedytapper_transaction_debt_check_statement FROM @speedytapper_transaction_debt_check_sql;
EXECUTE speedytapper_transaction_debt_check_statement;
DEALLOCATE PREPARE speedytapper_transaction_debt_check_statement;
