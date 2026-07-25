-- MySQL and MariaDB auto-commit DDL. Every operation is conditional so an
-- interrupted deployment can safely rerun this migration.
SET @speedytapper_game_center_publication_columns = (
    SELECT CONCAT_WS(', ',
        IF(SUM(COLUMN_NAME = 'game_player_id_hash') = 0,
            'ADD COLUMN game_player_id_hash BINARY(32) NULL AFTER team_player_id_hash', NULL),
        IF(SUM(COLUMN_NAME = 'game_player_id_ciphertext') = 0,
            'ADD COLUMN game_player_id_ciphertext VARBINARY(512) NULL AFTER game_player_id_hash', NULL),
        IF(SUM(COLUMN_NAME = 'game_player_id_iv') = 0,
            'ADD COLUMN game_player_id_iv BINARY(12) NULL AFTER game_player_id_ciphertext', NULL),
        IF(SUM(COLUMN_NAME = 'game_player_id_tag') = 0,
            'ADD COLUMN game_player_id_tag BINARY(16) NULL AFTER game_player_id_iv', NULL),
        IF(SUM(COLUMN_NAME = 'publication_enabled_at') = 0,
            'ADD COLUMN publication_enabled_at TIMESTAMP(3) NULL AFTER last_verified_at', NULL),
        IF(SUM(COLUMN_NAME = 'publication_disabled_at') = 0,
            'ADD COLUMN publication_disabled_at TIMESTAMP(3) NULL AFTER publication_enabled_at', NULL)
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_game_center_bindings'
);

SET @speedytapper_game_center_publication_columns_sql = IF(
    @speedytapper_game_center_publication_columns IS NULL
        OR @speedytapper_game_center_publication_columns = '',
    'DO 1',
    CONCAT(
        'ALTER TABLE player_game_center_bindings ',
        @speedytapper_game_center_publication_columns
    )
);
PREPARE speedytapper_game_center_publication_columns_statement
    FROM @speedytapper_game_center_publication_columns_sql;
EXECUTE speedytapper_game_center_publication_columns_statement;
DEALLOCATE PREPARE speedytapper_game_center_publication_columns_statement;

SET @speedytapper_game_center_player_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_game_center_bindings'
      AND INDEX_NAME = 'player_game_center_game_player_unique'
);
SET @speedytapper_game_center_player_index_sql = IF(
    @speedytapper_game_center_player_index_exists = 0,
    'ALTER TABLE player_game_center_bindings '
        'ADD UNIQUE KEY player_game_center_game_player_unique (game_player_id_hash)',
    'DO 1'
);
PREPARE speedytapper_game_center_player_index_statement
    FROM @speedytapper_game_center_player_index_sql;
EXECUTE speedytapper_game_center_player_index_statement;
DEALLOCATE PREPARE speedytapper_game_center_player_index_statement;

SET @speedytapper_game_center_fields_check_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_game_center_bindings'
      AND CONSTRAINT_NAME = 'player_game_center_publication_fields_check'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @speedytapper_game_center_fields_check_sql = IF(
    @speedytapper_game_center_fields_check_exists = 0,
    'ALTER TABLE player_game_center_bindings '
        'ADD CONSTRAINT player_game_center_publication_fields_check CHECK ('
            '(game_player_id_hash IS NULL '
                'AND game_player_id_ciphertext IS NULL '
                'AND game_player_id_iv IS NULL '
                'AND game_player_id_tag IS NULL '
                'AND publication_enabled_at IS NULL) '
            'OR '
            '(game_player_id_hash IS NOT NULL '
                'AND game_player_id_ciphertext IS NOT NULL '
                'AND game_player_id_iv IS NOT NULL '
                'AND game_player_id_tag IS NOT NULL '
                'AND publication_enabled_at IS NOT NULL)'
        ')',
    'DO 1'
);
PREPARE speedytapper_game_center_fields_check_statement
    FROM @speedytapper_game_center_fields_check_sql;
EXECUTE speedytapper_game_center_fields_check_statement;
DEALLOCATE PREPARE speedytapper_game_center_fields_check_statement;

CREATE TABLE IF NOT EXISTS game_center_publication_outbox (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    publication_kind ENUM('leaderboard', 'achievement') NOT NULL,
    vendor_identifier VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pre_released TINYINT(1) NOT NULL,
    desired_value BIGINT UNSIGNED NULL,
    delivered_value BIGINT UNSIGNED NULL,
    desired_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    state ENUM(
        'pending',
        'processing',
        'retry',
        'succeeded',
        'cancelled',
        'needs_reset',
        'held'
    ) NOT NULL DEFAULT 'pending',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    available_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    lock_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    locked_at TIMESTAMP(3) NULL,
    apple_submission_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_http_status SMALLINT UNSIGNED NULL,
    last_error_code VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ON UPDATE CURRENT_TIMESTAMP(3),
    delivered_at TIMESTAMP(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY game_center_outbox_desired_state_unique (
        player_id,
        publication_kind,
        vendor_identifier,
        pre_released
    ),
    KEY game_center_outbox_dispatch_index (state, available_at, created_at),
    KEY game_center_outbox_lease_index (state, locked_at),
    CONSTRAINT game_center_outbox_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT game_center_outbox_value_check CHECK (
        (
            publication_kind = 'leaderboard'
            AND (desired_value IS NULL OR desired_value <= 9223372036854775807)
        )
        OR
        (
            publication_kind = 'achievement'
            AND desired_value = 100
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @speedytapper_game_center_outbox_state_has_held = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'game_center_publication_outbox'
      AND COLUMN_NAME = 'state'
      AND COLUMN_TYPE LIKE '%''held''%'
);
SET @speedytapper_game_center_outbox_state_sql = IF(
    @speedytapper_game_center_outbox_state_has_held = 0,
    'ALTER TABLE game_center_publication_outbox MODIFY COLUMN state '
        'ENUM(''pending'',''processing'',''retry'',''succeeded'','
            '''cancelled'',''needs_reset'',''held'') '
        'NOT NULL DEFAULT ''pending''',
    'DO 1'
);
PREPARE speedytapper_game_center_outbox_state_statement
    FROM @speedytapper_game_center_outbox_state_sql;
EXECUTE speedytapper_game_center_outbox_state_statement;
DEALLOCATE PREPARE speedytapper_game_center_outbox_state_statement;
