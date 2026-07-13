SET @speedytapper_total_coins_collected_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'total_coins_collected'
);

SET @speedytapper_total_coins_collected_sql = IF(
    @speedytapper_total_coins_collected_exists = 0,
    'ALTER TABLE players ADD COLUMN total_coins_collected BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER coins',
    'SELECT 1'
);

PREPARE speedytapper_total_coins_collected_statement
    FROM @speedytapper_total_coins_collected_sql;
EXECUTE speedytapper_total_coins_collected_statement;
DEALLOCATE PREPARE speedytapper_total_coins_collected_statement;

UPDATE players AS player
LEFT JOIN (
    SELECT player_id, SUM(coins_awarded) AS run_coins_collected
    FROM completed_runs
    GROUP BY player_id
) AS run_coins ON run_coins.player_id = player.id
SET player.total_coins_collected = GREATEST(
    player.total_coins_collected,
    player.coins,
    COALESCE(run_coins.run_coins_collected, 0)
);

CREATE TABLE IF NOT EXISTS player_achievements (
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    achievement_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reward_coins SMALLINT UNSIGNED NOT NULL,
    unlocked_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    claimed_at TIMESTAMP(3) NULL,
    PRIMARY KEY (player_id, achievement_key),
    KEY player_achievements_claimed_index (player_id, claimed_at),
    CONSTRAINT player_achievements_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT player_achievements_reward_positive CHECK (reward_coins > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO player_achievements
    (player_id, achievement_key, reward_coins, unlocked_at)
SELECT player_id, 'complete_arcade', 1, MIN(completed_at)
FROM completed_runs
WHERE mode = 'normal'
GROUP BY player_id;

INSERT IGNORE INTO player_achievements
    (player_id, achievement_key, reward_coins, unlocked_at)
SELECT player_id, 'complete_zen', 1, MIN(completed_at)
FROM completed_runs
WHERE mode = 'zen' AND duration_ms = 180000
GROUP BY player_id;

INSERT IGNORE INTO player_achievements
    (player_id, achievement_key, reward_coins, unlocked_at)
SELECT player_id, 'godlike_speed', 1, MIN(achieved_at)
FROM leaderboard_entries
WHERE godlike_count > 0
GROUP BY player_id;

INSERT IGNORE INTO player_achievements
    (player_id, achievement_key, reward_coins, unlocked_at)
SELECT player_id, 'score_over_100k', 5, MIN(completed_at)
FROM completed_runs
WHERE score > 100000
GROUP BY player_id;

INSERT IGNORE INTO player_achievements
    (player_id, achievement_key, reward_coins, unlocked_at)
SELECT id, 'collect_5_coins', 5, updated_at
FROM players
WHERE total_coins_collected >= 5;

SET @speedytapper_player_pets_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_pets'
);

SET @speedytapper_pet_achievement_sql = IF(
    @speedytapper_player_pets_exists > 0,
    'INSERT IGNORE INTO player_achievements (player_id, achievement_key, reward_coins, unlocked_at) SELECT player_id, ''buy_a_pet'', 10, MIN(acquired_at) FROM player_pets WHERE acquisition_source = ''purchase'' GROUP BY player_id',
    'SELECT 1'
);

PREPARE speedytapper_pet_achievement_statement FROM @speedytapper_pet_achievement_sql;
EXECUTE speedytapper_pet_achievement_statement;
DEALLOCATE PREPARE speedytapper_pet_achievement_statement;
