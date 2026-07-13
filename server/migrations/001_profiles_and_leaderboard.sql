CREATE TABLE IF NOT EXISTS seasons (
    id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(80) NOT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS players (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    google_subject_hash BINARY(32) NOT NULL,
    nickname VARCHAR(20) NOT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    last_login_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY players_google_subject_hash_unique (google_subject_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leaderboard_entries (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mode ENUM('normal', 'zen') NOT NULL,
    score INT UNSIGNED NOT NULL,
    duration_ms BIGINT UNSIGNED NOT NULL,
    fastest_reaction_ms INT UNSIGNED NULL,
    average_reaction_ms INT UNSIGNED NULL,
    correct_taps INT UNSIGNED NOT NULL,
    dodge_count INT UNSIGNED NOT NULL,
    godlike_count INT UNSIGNED NOT NULL,
    perfect_count INT UNSIGNED NOT NULL,
    great_count INT UNSIGNED NOT NULL,
    good_count INT UNSIGNED NOT NULL,
    achieved_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY leaderboard_player_mode_season_unique (season_id, player_id, mode),
    KEY leaderboard_ranking_index (season_id, mode, score DESC, duration_ms DESC, correct_taps DESC, achieved_at, id),
    CONSTRAINT leaderboard_season_foreign FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT leaderboard_player_foreign FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT leaderboard_ratings_match_taps CHECK (
        godlike_count + perfect_count + great_count + good_count = correct_taps
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
