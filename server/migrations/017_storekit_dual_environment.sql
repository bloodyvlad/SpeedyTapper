-- Scope retained Apple transaction and notification identities by signed
-- environment so Sandbox and Production can run concurrently without
-- colliding. Existing production data was Sandbox-only at this migration.
--
-- MySQL auto-commits DDL, so every schema operation in this migration is
-- intentionally retry-safe. The data conversion updates every child before
-- changing its parent transaction ID, which lets a retry reconstruct the
-- mapping after an interruption anywhere before the parent update.

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchased_coin_lots'
      AND CONSTRAINT_NAME = 'purchased_coin_lot_transaction_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'DO 1',
    'ALTER TABLE purchased_coin_lots DROP FOREIGN KEY purchased_coin_lot_transaction_foreign'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_transaction_observations'
      AND CONSTRAINT_NAME = 'storekit_transaction_observation_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'DO 1',
    'ALTER TABLE storekit_transaction_observations DROP FOREIGN KEY storekit_transaction_observation_foreign'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_entitlement_sources'
      AND CONSTRAINT_NAME = 'player_entitlement_transaction_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'DO 1',
    'ALTER TABLE player_entitlement_sources DROP FOREIGN KEY player_entitlement_transaction_foreign'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_refund_debt_allocations'
      AND CONSTRAINT_NAME = 'storekit_refund_debt_source_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'DO 1',
    'ALTER TABLE storekit_refund_debt_allocations DROP FOREIGN KEY storekit_refund_debt_source_foreign'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_refund_debt_allocations'
      AND CONSTRAINT_NAME = 'storekit_refund_debt_target_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'DO 1',
    'ALTER TABLE storekit_refund_debt_allocations DROP FOREIGN KEY storekit_refund_debt_target_foreign'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'coin_spend_allocations'
      AND CONSTRAINT_NAME = 'coin_spend_lot_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'DO 1',
    'ALTER TABLE coin_spend_allocations DROP FOREIGN KEY coin_spend_lot_foreign'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_notifications'
      AND CONSTRAINT_NAME = 'storekit_notification_transaction_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'DO 1',
    'ALTER TABLE storekit_notifications DROP FOREIGN KEY storekit_notification_transaction_foreign'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_refund_cosmetics'
      AND CONSTRAINT_NAME = 'storekit_refund_cosmetic_transaction_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'DO 1',
    'ALTER TABLE storekit_refund_cosmetics DROP FOREIGN KEY storekit_refund_cosmetic_transaction_foreign'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_cosmetic_restore_debts'
      AND CONSTRAINT_NAME = 'storekit_cosmetic_restore_debt_transaction_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'DO 1',
    'ALTER TABLE storekit_cosmetic_restore_debts DROP FOREIGN KEY storekit_cosmetic_restore_debt_transaction_foreign'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

ALTER TABLE storekit_transactions
    MODIFY COLUMN transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

SET @speedytapper_column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_transactions'
      AND COLUMN_NAME = 'apple_transaction_id'
);
SET @speedytapper_ddl = IF(
    @speedytapper_column_exists = 0,
    'ALTER TABLE storekit_transactions ADD COLUMN apple_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER transaction_id',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

ALTER TABLE purchased_coin_lots
    MODIFY COLUMN transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE storekit_transaction_observations
    MODIFY COLUMN transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE player_entitlement_sources
    MODIFY COLUMN source_transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE storekit_refund_debt_allocations
    MODIFY COLUMN source_reference VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    MODIFY COLUMN source_purchase_transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
    MODIFY COLUMN refund_transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE coin_spend_allocations
    MODIFY COLUMN lot_transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE storekit_notifications
    MODIFY COLUMN notification_uuid VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    MODIFY COLUMN transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL;

SET @speedytapper_column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_notifications'
      AND COLUMN_NAME = 'apple_notification_uuid'
);
SET @speedytapper_ddl = IF(
    @speedytapper_column_exists = 0,
    'ALTER TABLE storekit_notifications ADD COLUMN apple_notification_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER notification_uuid',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

ALTER TABLE storekit_refund_cosmetics
    MODIFY COLUMN refund_transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE storekit_cosmetic_restore_debts
    MODIFY COLUMN refund_transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

DROP TEMPORARY TABLE IF EXISTS speedytapper_storekit_scope_map;
CREATE TEMPORARY TABLE speedytapper_storekit_scope_map (
    apple_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    storage_transaction_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (apple_transaction_id)
) ENGINE=InnoDB;

INSERT INTO speedytapper_storekit_scope_map (apple_transaction_id, storage_transaction_id)
SELECT transaction_id, CONCAT(environment, ':', transaction_id)
FROM storekit_transactions
WHERE apple_transaction_id IS NULL;

UPDATE purchased_coin_lots AS child
INNER JOIN speedytapper_storekit_scope_map AS scope
    ON scope.apple_transaction_id = child.transaction_id
SET child.transaction_id = scope.storage_transaction_id;
UPDATE storekit_transaction_observations AS child
INNER JOIN speedytapper_storekit_scope_map AS scope
    ON scope.apple_transaction_id = child.transaction_id
SET child.transaction_id = scope.storage_transaction_id;
UPDATE player_entitlement_sources AS child
INNER JOIN speedytapper_storekit_scope_map AS scope
    ON scope.apple_transaction_id = child.source_transaction_id
SET child.source_transaction_id = scope.storage_transaction_id;
UPDATE storekit_refund_debt_allocations AS child
INNER JOIN speedytapper_storekit_scope_map AS scope
    ON scope.apple_transaction_id = child.source_purchase_transaction_id
SET child.source_purchase_transaction_id = scope.storage_transaction_id,
    child.source_reference = CASE
        WHEN child.source_type = 'storekit_purchase'
            AND child.source_reference = scope.apple_transaction_id
        THEN scope.storage_transaction_id
        ELSE child.source_reference
    END;
UPDATE storekit_refund_debt_allocations AS child
INNER JOIN speedytapper_storekit_scope_map AS scope
    ON scope.apple_transaction_id = child.refund_transaction_id
SET child.refund_transaction_id = scope.storage_transaction_id;
UPDATE coin_spend_allocations AS child
INNER JOIN speedytapper_storekit_scope_map AS scope
    ON scope.apple_transaction_id = child.lot_transaction_id
SET child.lot_transaction_id = scope.storage_transaction_id;
UPDATE storekit_notifications AS child
INNER JOIN speedytapper_storekit_scope_map AS scope
    ON scope.apple_transaction_id = child.transaction_id
SET child.transaction_id = scope.storage_transaction_id;
UPDATE storekit_refund_cosmetics AS child
INNER JOIN speedytapper_storekit_scope_map AS scope
    ON scope.apple_transaction_id = child.refund_transaction_id
SET child.refund_transaction_id = scope.storage_transaction_id;
UPDATE storekit_cosmetic_restore_debts AS child
INNER JOIN speedytapper_storekit_scope_map AS scope
    ON scope.apple_transaction_id = child.refund_transaction_id
SET child.refund_transaction_id = scope.storage_transaction_id;

UPDATE storekit_transactions AS transaction_row
INNER JOIN speedytapper_storekit_scope_map AS scope
    ON scope.apple_transaction_id = transaction_row.transaction_id
SET transaction_row.apple_transaction_id = scope.apple_transaction_id,
    transaction_row.transaction_id = scope.storage_transaction_id;

UPDATE storekit_transactions
SET app_apple_id = 6792328590
WHERE app_apple_id IS NULL;

UPDATE storekit_notifications
SET apple_notification_uuid = notification_uuid,
    notification_uuid = CONCAT(environment, ':', notification_uuid)
WHERE apple_notification_uuid IS NULL;

ALTER TABLE storekit_transactions
    MODIFY COLUMN apple_transaction_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE storekit_notifications
    MODIFY COLUMN apple_notification_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

SET @speedytapper_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_transactions'
      AND INDEX_NAME = 'storekit_environment_apple_transaction_unique'
);
SET @speedytapper_ddl = IF(
    @speedytapper_index_exists = 0,
    'ALTER TABLE storekit_transactions ADD UNIQUE KEY storekit_environment_apple_transaction_unique (environment, apple_transaction_id)',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_notifications'
      AND INDEX_NAME = 'storekit_environment_apple_notification_unique'
);
SET @speedytapper_ddl = IF(
    @speedytapper_index_exists = 0,
    'ALTER TABLE storekit_notifications ADD UNIQUE KEY storekit_environment_apple_notification_unique (environment, apple_notification_uuid)',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_notifications'
      AND INDEX_NAME = 'storekit_notification_environment_time_index'
);
SET @speedytapper_ddl = IF(
    @speedytapper_index_exists = 0,
    'ALTER TABLE storekit_notifications ADD KEY storekit_notification_environment_time_index (environment, signed_date_ms, notification_uuid)',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_storekit_family_bindings'
      AND COLUMN_NAME = 'environment'
);
SET @speedytapper_ddl = IF(
    @speedytapper_column_exists = 0,
    'ALTER TABLE player_storekit_family_bindings ADD COLUMN environment ENUM(''Sandbox'',''Production'') NOT NULL DEFAULT ''Sandbox'' FIRST',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_primary_columns = (
    SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',')
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_storekit_family_bindings'
      AND CONSTRAINT_NAME = 'PRIMARY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_primary_columns = 'environment,app_transaction_pseudonym',
    'DO 1',
    'ALTER TABLE player_storekit_family_bindings DROP PRIMARY KEY, ADD PRIMARY KEY (environment, app_transaction_pseudonym)'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchased_coin_lots'
      AND CONSTRAINT_NAME = 'purchased_coin_lot_transaction_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'ALTER TABLE purchased_coin_lots ADD CONSTRAINT purchased_coin_lot_transaction_foreign FOREIGN KEY (transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_transaction_observations'
      AND CONSTRAINT_NAME = 'storekit_transaction_observation_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'ALTER TABLE storekit_transaction_observations ADD CONSTRAINT storekit_transaction_observation_foreign FOREIGN KEY (transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_entitlement_sources'
      AND CONSTRAINT_NAME = 'player_entitlement_transaction_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'ALTER TABLE player_entitlement_sources ADD CONSTRAINT player_entitlement_transaction_foreign FOREIGN KEY (source_transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_refund_debt_allocations'
      AND CONSTRAINT_NAME = 'storekit_refund_debt_source_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'ALTER TABLE storekit_refund_debt_allocations ADD CONSTRAINT storekit_refund_debt_source_foreign FOREIGN KEY (source_purchase_transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_refund_debt_allocations'
      AND CONSTRAINT_NAME = 'storekit_refund_debt_target_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'ALTER TABLE storekit_refund_debt_allocations ADD CONSTRAINT storekit_refund_debt_target_foreign FOREIGN KEY (refund_transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'coin_spend_allocations'
      AND CONSTRAINT_NAME = 'coin_spend_lot_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'ALTER TABLE coin_spend_allocations ADD CONSTRAINT coin_spend_lot_foreign FOREIGN KEY (lot_transaction_id) REFERENCES purchased_coin_lots (transaction_id) ON DELETE RESTRICT',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_notifications'
      AND CONSTRAINT_NAME = 'storekit_notification_transaction_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'ALTER TABLE storekit_notifications ADD CONSTRAINT storekit_notification_transaction_foreign FOREIGN KEY (transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE SET NULL',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_refund_cosmetics'
      AND CONSTRAINT_NAME = 'storekit_refund_cosmetic_transaction_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'ALTER TABLE storekit_refund_cosmetics ADD CONSTRAINT storekit_refund_cosmetic_transaction_foreign FOREIGN KEY (refund_transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

SET @speedytapper_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_cosmetic_restore_debts'
      AND CONSTRAINT_NAME = 'storekit_cosmetic_restore_debt_transaction_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_ddl = IF(
    @speedytapper_fk_exists = 0,
    'ALTER TABLE storekit_cosmetic_restore_debts ADD CONSTRAINT storekit_cosmetic_restore_debt_transaction_foreign FOREIGN KEY (refund_transaction_id) REFERENCES storekit_transactions (transaction_id) ON DELETE RESTRICT',
    'DO 1'
);
PREPARE speedytapper_ddl_statement FROM @speedytapper_ddl;
EXECUTE speedytapper_ddl_statement;
DEALLOCATE PREPARE speedytapper_ddl_statement;

UPDATE storekit_reconciliation_state
SET last_transaction_cursor = NULL;

DROP TEMPORARY TABLE IF EXISTS speedytapper_storekit_scope_map;
