SET @speedytapper_coins_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'coins'
);

SET @speedytapper_coins_sql = IF(
    @speedytapper_coins_exists = 0,
    'ALTER TABLE players ADD COLUMN coins BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER nickname_confirmed',
    'DO 1'
);

PREPARE speedytapper_coins_statement FROM @speedytapper_coins_sql;
EXECUTE speedytapper_coins_statement;
DEALLOCATE PREPARE speedytapper_coins_statement;

SET @speedytapper_coin_remainder_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'coin_time_remainder_ms'
);

SET @speedytapper_coin_remainder_sql = IF(
    @speedytapper_coin_remainder_exists = 0,
    'ALTER TABLE players ADD COLUMN coin_time_remainder_ms INT UNSIGNED NOT NULL DEFAULT 0 AFTER coins',
    'DO 1'
);

PREPARE speedytapper_coin_remainder_statement FROM @speedytapper_coin_remainder_sql;
EXECUTE speedytapper_coin_remainder_statement;
DEALLOCATE PREPARE speedytapper_coin_remainder_statement;

SET @speedytapper_total_play_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'total_play_ms'
);

SET @speedytapper_total_play_sql = IF(
    @speedytapper_total_play_exists = 0,
    'ALTER TABLE players ADD COLUMN total_play_ms BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER coin_time_remainder_ms',
    'DO 1'
);

PREPARE speedytapper_total_play_statement FROM @speedytapper_total_play_sql;
EXECUTE speedytapper_total_play_statement;
DEALLOCATE PREPARE speedytapper_total_play_statement;

SET @speedytapper_coin_remainder_check_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND CONSTRAINT_NAME = 'players_coin_remainder_range'
      AND CONSTRAINT_TYPE = 'CHECK'
);

SET @speedytapper_coin_remainder_check_sql = IF(
    @speedytapper_coin_remainder_check_exists = 0,
    'ALTER TABLE players ADD CONSTRAINT players_coin_remainder_range CHECK (coin_time_remainder_ms < 60000)',
    'DO 1'
);

PREPARE speedytapper_coin_remainder_check_statement FROM @speedytapper_coin_remainder_check_sql;
EXECUTE speedytapper_coin_remainder_check_statement;
DEALLOCATE PREPARE speedytapper_coin_remainder_check_statement;

CREATE TABLE IF NOT EXISTS completed_runs (
    run_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    mode ENUM('normal', 'zen') NOT NULL,
    score INT UNSIGNED NOT NULL,
    duration_ms BIGINT UNSIGNED NOT NULL,
    reaction_base_points INT UNSIGNED NOT NULL,
    multiplier_bonus_points INT UNSIGNED NOT NULL,
    max_multiplier TINYINT UNSIGNED NOT NULL,
    multiplier_1_hits INT UNSIGNED NOT NULL,
    multiplier_2_hits INT UNSIGNED NOT NULL,
    multiplier_3_hits INT UNSIGNED NOT NULL,
    multiplier_4_hits INT UNSIGNED NOT NULL,
    multiplier_5_hits INT UNSIGNED NOT NULL,
    multiplier_1_base_points INT UNSIGNED NOT NULL,
    multiplier_2_base_points INT UNSIGNED NOT NULL,
    multiplier_3_base_points INT UNSIGNED NOT NULL,
    multiplier_4_base_points INT UNSIGNED NOT NULL,
    multiplier_5_base_points INT UNSIGNED NOT NULL,
    coins_awarded INT UNSIGNED NOT NULL,
    leaderboard_improved TINYINT(1) NOT NULL,
    completed_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (run_id),
    KEY completed_runs_player_time_index (player_id, completed_at),
    CONSTRAINT completed_runs_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT completed_runs_multiplier_range CHECK (max_multiplier BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
