-- Forward-only hardening for installations that already recorded migration 014
-- before its clean-install StoreKit schema was finalized.

SET @speedytapper_actor_foreign_delete_rule = (
    SELECT MAX(DELETE_RULE)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'account_reward_resets'
      AND CONSTRAINT_NAME = 'account_reward_resets_actor_foreign'
);
SET @speedytapper_drop_restrict_actor_foreign_sql = IF(
    @speedytapper_actor_foreign_delete_rule IS NULL
        OR @speedytapper_actor_foreign_delete_rule = 'SET NULL',
    'DO 1',
    'ALTER TABLE account_reward_resets DROP FOREIGN KEY account_reward_resets_actor_foreign'
);
PREPARE speedytapper_drop_restrict_actor_foreign_statement
    FROM @speedytapper_drop_restrict_actor_foreign_sql;
EXECUTE speedytapper_drop_restrict_actor_foreign_statement;
DEALLOCATE PREPARE speedytapper_drop_restrict_actor_foreign_statement;

SET @speedytapper_actor_column_not_nullable = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'account_reward_resets'
      AND COLUMN_NAME = 'actor_player_id'
      AND IS_NULLABLE = 'NO'
);
SET @speedytapper_make_actor_nullable_sql = IF(
    @speedytapper_actor_column_not_nullable = 0,
    'DO 1',
    'ALTER TABLE account_reward_resets MODIFY COLUMN actor_player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL'
);
PREPARE speedytapper_make_actor_nullable_statement
    FROM @speedytapper_make_actor_nullable_sql;
EXECUTE speedytapper_make_actor_nullable_statement;
DEALLOCATE PREPARE speedytapper_make_actor_nullable_statement;

SET @speedytapper_actor_foreign_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'account_reward_resets'
      AND CONSTRAINT_NAME = 'account_reward_resets_actor_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @speedytapper_add_actor_set_null_foreign_sql = IF(
    @speedytapper_actor_foreign_exists = 0,
    'ALTER TABLE account_reward_resets ADD CONSTRAINT account_reward_resets_actor_foreign FOREIGN KEY (actor_player_id) REFERENCES players (id) ON DELETE SET NULL',
    'DO 1'
);
PREPARE speedytapper_add_actor_set_null_foreign_statement
    FROM @speedytapper_add_actor_set_null_foreign_sql;
EXECUTE speedytapper_add_actor_set_null_foreign_statement;
DEALLOCATE PREPARE speedytapper_add_actor_set_null_foreign_statement;

SET @speedytapper_storekit_environment_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_transactions'
      AND INDEX_NAME = 'storekit_environment_transaction_index'
);
SET @speedytapper_add_storekit_environment_index_sql = IF(
    @speedytapper_storekit_environment_index_exists = 0,
    'ALTER TABLE storekit_transactions ADD KEY storekit_environment_transaction_index (environment, transaction_id)',
    'DO 1'
);
PREPARE speedytapper_add_storekit_environment_index_statement
    FROM @speedytapper_add_storekit_environment_index_sql;
EXECUTE speedytapper_add_storekit_environment_index_statement;
DEALLOCATE PREPARE speedytapper_add_storekit_environment_index_statement;

SET @speedytapper_storekit_lifecycle_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_transactions'
      AND COLUMN_NAME = 'lifecycle_signed_date_ms'
);
SET @speedytapper_add_storekit_lifecycle_column_sql = IF(
    @speedytapper_storekit_lifecycle_column_exists = 0,
    'ALTER TABLE storekit_transactions ADD COLUMN lifecycle_signed_date_ms BIGINT UNSIGNED NULL AFTER signed_date_ms',
    'DO 1'
);
PREPARE speedytapper_add_storekit_lifecycle_column_statement
    FROM @speedytapper_add_storekit_lifecycle_column_sql;
EXECUTE speedytapper_add_storekit_lifecycle_column_statement;
DEALLOCATE PREPARE speedytapper_add_storekit_lifecycle_column_statement;

UPDATE storekit_transactions
SET lifecycle_signed_date_ms = signed_date_ms
WHERE lifecycle_signed_date_ms IS NULL;

SET @speedytapper_storekit_lifecycle_column_nullable = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storekit_transactions'
      AND COLUMN_NAME = 'lifecycle_signed_date_ms'
      AND IS_NULLABLE = 'YES'
);
SET @speedytapper_require_storekit_lifecycle_column_sql = IF(
    @speedytapper_storekit_lifecycle_column_nullable = 0,
    'DO 1',
    'ALTER TABLE storekit_transactions MODIFY COLUMN lifecycle_signed_date_ms BIGINT UNSIGNED NOT NULL'
);
PREPARE speedytapper_require_storekit_lifecycle_column_statement
    FROM @speedytapper_require_storekit_lifecycle_column_sql;
EXECUTE speedytapper_require_storekit_lifecycle_column_statement;
DEALLOCATE PREPARE speedytapper_require_storekit_lifecycle_column_statement;
