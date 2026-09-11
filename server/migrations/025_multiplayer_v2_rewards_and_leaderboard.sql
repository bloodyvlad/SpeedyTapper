-- New revision-3 matches only. No historical result, wallet, Arcade, or season
-- backfill/reset. Records have only an owner FK: deleting another participant's
-- shared alpha result must not erase this player's earned-value provenance.
CREATE TABLE IF NOT EXISTS multiplayer_v2_reward_receipts (
    match_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    result_revision SMALLINT UNSIGNED NOT NULL,
    gameplay_revision SMALLINT UNSIGNED NOT NULL,
    reward_policy VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    match_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    completion_reason VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reported_economy_generation INT UNSIGNED NULL,
    economy_generation INT UNSIGNED NOT NULL,
    eligible_alive_ms INT UNSIGNED NOT NULL,
    credited_alive_ms INT UNSIGNED NOT NULL,
    coins_awarded INT UNSIGNED NOT NULL,
    remainder_before_ms INT UNSIGNED NOT NULL,
    remainder_after_ms INT UNSIGNED NOT NULL,
    total_alive_ms BIGINT UNSIGNED NOT NULL,
    coin_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ledger_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (match_id, player_id),
    KEY multiplayer_v2_reward_player_generation (player_id, economy_generation, created_at),
    CONSTRAINT multiplayer_v2_reward_owner_fk FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_v2_reward_revision CHECK (result_revision = 2 AND gameplay_revision = 3
        AND reward_policy = 'multiplayer-alive-minute-v1'),
    CONSTRAINT multiplayer_v2_reward_kind CHECK (match_kind IN ('competitive','tutorial')
        AND completion_reason IN ('completed','aborted')),
    CONSTRAINT multiplayer_v2_reward_bounds CHECK (eligible_alive_ms <= 900000
        AND credited_alive_ms <= eligible_alive_ms AND coins_awarded <= 30
        AND MOD(coins_awarded, 2) = 0 AND remainder_before_ms < 60000 AND remainder_after_ms < 60000),
    CONSTRAINT multiplayer_v2_reward_status CHECK (coin_status IN
        ('eligible','ineligible','missing_generation','stale_generation'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A fresh public lane, intentionally independent of old v1 test leaderboard
-- rows and unrewarded v2 alpha aggregates. Old evidence is not copied or erased.
CREATE TABLE IF NOT EXISTS multiplayer_v2_leaderboard_entries (
    entry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    match_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    placement TINYINT UNSIGNED NOT NULL,
    player_count TINYINT UNSIGNED NOT NULL,
    seat TINYINT UNSIGNED NOT NULL,
    score INT UNSIGNED NOT NULL,
    survival_ms INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL,
    misses SMALLINT UNSIGNED NOT NULL,
    dodges INT UNSIGNED NOT NULL,
    fastest_reaction_ms INT UNSIGNED NULL,
    average_reaction_ms INT UNSIGNED NULL,
    max_multiplier TINYINT UNSIGNED NOT NULL,
    achieved_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (entry_id),
    UNIQUE KEY multiplayer_v2_board_match_player (match_id, player_id),
    KEY multiplayer_v2_board_order (season_id, score, achieved_at, entry_id),
    KEY multiplayer_v2_board_player (player_id, season_id),
    CONSTRAINT multiplayer_v2_board_owner_fk FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_v2_board_bounds CHECK (player_count BETWEEN 2 AND 4
        AND placement BETWEEN 1 AND player_count AND seat < player_count
        AND score <= 100000000 AND survival_ms <= 900000 AND hits <= 100000
        AND misses <= 1000 AND dodges <= 100000 AND max_multiplier BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
