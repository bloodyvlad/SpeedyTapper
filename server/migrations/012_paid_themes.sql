CREATE TABLE IF NOT EXISTS themes (
    id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(40) NOT NULL,
    price_coins INT UNSIGNED NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY themes_sort_order_unique (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO themes (id, display_name, price_coins, sort_order, active) VALUES
    ('classic', 'Default', 0, 1, 1),
    ('disco', 'Disco', 0, 2, 1),
    ('light', 'Light', 50, 3, 1),
    ('pixel', 'Pixel', 100, 4, 1)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    price_coins = VALUES(price_coins),
    sort_order = VALUES(sort_order),
    active = VALUES(active);

CREATE TABLE IF NOT EXISTS player_themes (
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    theme_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    price_paid INT UNSIGNED NOT NULL,
    acquired_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (player_id, theme_id),
    KEY player_themes_theme_index (theme_id),
    CONSTRAINT player_themes_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT player_themes_theme_foreign
        FOREIGN KEY (theme_id) REFERENCES themes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_theme_selection (
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    theme_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    selected_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (player_id),
    CONSTRAINT player_theme_selection_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT player_theme_selection_theme_foreign
        FOREIGN KEY (theme_id) REFERENCES themes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE coin_ledger
    MODIFY COLUMN event_type ENUM(
        'run_credit',
        'migration_credit',
        'pet_purchase',
        'theme_purchase',
        'achievement_reward',
        'moderation_reconcile',
        'manual_reconcile',
        'admin_reward_reset'
    ) NOT NULL;

SET @speedytapper_themes_removed_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'account_reward_resets'
      AND COLUMN_NAME = 'themes_removed'
);
SET @speedytapper_themes_removed_sql = IF(
    @speedytapper_themes_removed_exists = 0,
    'ALTER TABLE account_reward_resets ADD COLUMN themes_removed INT UNSIGNED NOT NULL DEFAULT 0 AFTER pet_ids_json',
    'DO 1'
);
PREPARE speedytapper_themes_removed_statement FROM @speedytapper_themes_removed_sql;
EXECUTE speedytapper_themes_removed_statement;
DEALLOCATE PREPARE speedytapper_themes_removed_statement;

SET @speedytapper_theme_ids_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'account_reward_resets'
      AND COLUMN_NAME = 'theme_ids_json'
);
SET @speedytapper_theme_ids_sql = IF(
    @speedytapper_theme_ids_exists = 0,
    'ALTER TABLE account_reward_resets ADD COLUMN theme_ids_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER themes_removed',
    'DO 1'
);
PREPARE speedytapper_theme_ids_statement FROM @speedytapper_theme_ids_sql;
EXECUTE speedytapper_theme_ids_statement;
DEALLOCATE PREPARE speedytapper_theme_ids_statement;

UPDATE account_reward_resets SET theme_ids_json = '[]' WHERE theme_ids_json IS NULL;

ALTER TABLE account_reward_resets
    MODIFY COLUMN theme_ids_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
