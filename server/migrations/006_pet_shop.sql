CREATE TABLE IF NOT EXISTS pets (
    id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(40) NOT NULL,
    price_coins INT UNSIGNED NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY pets_sort_order_unique (sort_order),
    CONSTRAINT pets_price_positive CHECK (price_coins > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pets (id, display_name, price_coins, sort_order, active) VALUES
    ('foka', 'Foka', 10, 1, 1),
    ('kesha', 'Kesha', 20, 2, 1),
    ('tauta', 'Tauta', 50, 3, 1),
    ('misha', 'Misha', 100, 4, 1),
    ('pancake', 'Pancake', 500, 5, 1)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    price_coins = VALUES(price_coins),
    sort_order = VALUES(sort_order),
    active = VALUES(active);

CREATE TABLE IF NOT EXISTS player_pets (
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pet_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    price_paid INT UNSIGNED NOT NULL,
    acquisition_source ENUM('purchase', 'legacy_easter_egg') NOT NULL,
    acquired_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (player_id, pet_id),
    KEY player_pets_pet_index (pet_id),
    CONSTRAINT player_pets_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT player_pets_pet_foreign
        FOREIGN KEY (pet_id) REFERENCES pets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_pet_selection (
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pet_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    equipped_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (player_id),
    CONSTRAINT player_pet_selection_owned_foreign
        FOREIGN KEY (player_id, pet_id) REFERENCES player_pets (player_id, pet_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO player_pets (player_id, pet_id, price_paid, acquisition_source)
SELECT id, 'misha', 0, 'legacy_easter_egg'
FROM players
WHERE nickname_confirmed = 1
  AND LOWER(TRIM(nickname)) = 'misha_boy'
ON DUPLICATE KEY UPDATE
    player_id = VALUES(player_id),
    pet_id = VALUES(pet_id);

INSERT INTO player_pet_selection (player_id, pet_id)
SELECT player_id, pet_id
FROM player_pets
WHERE pet_id = 'misha'
  AND acquisition_source = 'legacy_easter_egg'
ON DUPLICATE KEY UPDATE
    player_id = VALUES(player_id);
