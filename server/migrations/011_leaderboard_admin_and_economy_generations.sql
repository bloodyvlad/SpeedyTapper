CREATE TABLE IF NOT EXISTS player_roles (
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_by VARCHAR(80) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    granted_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (player_id, role),
    KEY player_roles_role_index (role, player_id),
    CONSTRAINT player_roles_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bootstrap the requested production administrator only when both exact,
-- immutable public result IDs still resolve to the same internal player.
INSERT IGNORE INTO player_roles (player_id, role, granted_by, reason)
SELECT arcade.player_id,
       'leaderboard_admin',
       'migration-011',
       'Bootstrap verified by the exact Arcade and Zen result pair.'
FROM leaderboard_entries arcade
INNER JOIN leaderboard_entries zen
    ON zen.player_id = arcade.player_id
WHERE arcade.id = 'd4e98497-9212-475e-8664-283171ce3910'
  AND zen.id = '82ee646d-28d9-43f8-9e38-e4e234a02db1'
  AND arcade.mode = 'normal'
  AND arcade.score = 77825
  AND arcade.verification_status IN ('legacy', 'verified')
  AND zen.mode = 'zen'
  AND zen.verification_status IN ('legacy', 'verified')
  AND zen.season_id = arcade.season_id
LIMIT 1;

SET @speedytapper_player_generation_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'economy_generation'
);
SET @speedytapper_player_generation_sql = IF(
    @speedytapper_player_generation_exists = 0,
    'ALTER TABLE players ADD COLUMN economy_generation INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_play_ms',
    'DO 1'
);
PREPARE speedytapper_player_generation_statement FROM @speedytapper_player_generation_sql;
EXECUTE speedytapper_player_generation_statement;
DEALLOCATE PREPARE speedytapper_player_generation_statement;

SET @speedytapper_run_generation_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'completed_runs'
      AND COLUMN_NAME = 'economy_generation'
);
SET @speedytapper_run_generation_sql = IF(
    @speedytapper_run_generation_exists = 0,
    'ALTER TABLE completed_runs ADD COLUMN economy_generation INT UNSIGNED NOT NULL DEFAULT 0 AFTER player_id',
    'DO 1'
);
PREPARE speedytapper_run_generation_statement FROM @speedytapper_run_generation_sql;
EXECUTE speedytapper_run_generation_statement;
DEALLOCATE PREPARE speedytapper_run_generation_statement;

SET @speedytapper_ledger_generation_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'coin_ledger'
      AND COLUMN_NAME = 'economy_generation'
);
SET @speedytapper_ledger_generation_sql = IF(
    @speedytapper_ledger_generation_exists = 0,
    'ALTER TABLE coin_ledger ADD COLUMN economy_generation INT UNSIGNED NOT NULL DEFAULT 0 AFTER player_id',
    'DO 1'
);
PREPARE speedytapper_ledger_generation_statement FROM @speedytapper_ledger_generation_sql;
EXECUTE speedytapper_ledger_generation_statement;
DEALLOCATE PREPARE speedytapper_ledger_generation_statement;

ALTER TABLE coin_ledger
    MODIFY COLUMN event_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    MODIFY COLUMN event_type ENUM(
        'run_credit',
        'migration_credit',
        'pet_purchase',
        'achievement_reward',
        'moderation_reconcile',
        'manual_reconcile',
        'admin_reward_reset'
    ) NOT NULL;

ALTER TABLE leaderboard_moderation_events
    MODIFY COLUMN action ENUM(
        'approve',
        'reject',
        'quarantine',
        'restore',
        'delete',
        'reconcile',
        'delete_reset'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS account_reward_resets (
    reset_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    trigger_entry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    from_generation INT UNSIGNED NOT NULL,
    to_generation INT UNSIGNED NOT NULL,
    coins_removed BIGINT UNSIGNED NOT NULL,
    debt_cleared BIGINT UNSIGNED NOT NULL,
    remainder_removed_ms INT UNSIGNED NOT NULL,
    total_play_removed_ms BIGINT UNSIGNED NOT NULL,
    total_collected_removed BIGINT UNSIGNED NOT NULL,
    pets_removed INT UNSIGNED NOT NULL,
    pet_ids_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (reset_id),
    UNIQUE KEY account_reward_resets_trigger_unique (trigger_entry_id),
    KEY account_reward_resets_player_time_index (player_id, created_at),
    CONSTRAINT account_reward_resets_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE RESTRICT,
    CONSTRAINT account_reward_resets_actor_foreign
        FOREIGN KEY (actor_player_id) REFERENCES players (id) ON DELETE RESTRICT,
    CONSTRAINT account_reward_resets_generation_order CHECK (to_generation = from_generation + 1),
    CONSTRAINT account_reward_resets_remainder_range CHECK (remainder_removed_ms < 60000),
    CONSTRAINT account_reward_resets_pet_ids_json_valid CHECK (JSON_VALID(pet_ids_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @speedytapper_run_generation_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'completed_runs'
      AND INDEX_NAME = 'completed_runs_player_generation_coin_index'
);
SET @speedytapper_run_generation_index_sql = IF(
    @speedytapper_run_generation_index_exists = 0,
    'ALTER TABLE completed_runs ADD KEY completed_runs_player_generation_coin_index (player_id, economy_generation, coin_status, completed_at, run_id)',
    'DO 1'
);
PREPARE speedytapper_run_generation_index_statement FROM @speedytapper_run_generation_index_sql;
EXECUTE speedytapper_run_generation_index_statement;
DEALLOCATE PREPARE speedytapper_run_generation_index_statement;

SET @speedytapper_ledger_generation_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'coin_ledger'
      AND INDEX_NAME = 'coin_ledger_player_generation_index'
);
SET @speedytapper_ledger_generation_index_sql = IF(
    @speedytapper_ledger_generation_index_exists = 0,
    'ALTER TABLE coin_ledger ADD KEY coin_ledger_player_generation_index (player_id, economy_generation, created_at, event_id)',
    'DO 1'
);
PREPARE speedytapper_ledger_generation_index_statement FROM @speedytapper_ledger_generation_index_sql;
EXECUTE speedytapper_ledger_generation_index_statement;
DEALLOCATE PREPARE speedytapper_ledger_generation_index_statement;
