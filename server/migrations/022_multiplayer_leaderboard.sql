CREATE TABLE IF NOT EXISTS multiplayer_matches (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    mode ENUM('own_color') NOT NULL DEFAULT 'own_color',
    state ENUM(
        'forming',
        'active',
        'collecting',
        'settled',
        'review',
        'cancelled',
        'expired'
    ) NOT NULL DEFAULT 'forming',
    capacity TINYINT UNSIGNED NOT NULL,
    player_group INT UNSIGNED NOT NULL,
    build_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ruleset_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    protocol_version SMALLINT UNSIGNED NOT NULL,
    proof_version SMALLINT UNSIGNED NOT NULL,
    seed BINARY(32) NOT NULL,
    manifest_hash BINARY(32) NULL,
    roster_hash BINARY(32) NULL,
    coordinator_participant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    transcript_hash BINARY(32) NULL,
    duration_ms BIGINT UNSIGNED NULL,
    risk_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    risk_reasons JSON NULL,
    review_reason VARCHAR(255) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ON UPDATE CURRENT_TIMESTAMP(3),
    expires_at TIMESTAMP(3) NOT NULL,
    started_at TIMESTAMP(3) NULL,
    submission_deadline_at TIMESTAMP(3) NULL,
    settled_at TIMESTAMP(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY multiplayer_matches_player_group_unique (player_group),
    KEY multiplayer_matches_lobby_index (state, expires_at, created_at, id),
    KEY multiplayer_matches_creator_index (created_by_player_id, state, created_at),
    CONSTRAINT multiplayer_matches_season_foreign
        FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT multiplayer_matches_creator_foreign
        FOREIGN KEY (created_by_player_id) REFERENCES players (id) ON DELETE SET NULL,
    CONSTRAINT multiplayer_matches_capacity_check CHECK (capacity BETWEEN 2 AND 4),
    CONSTRAINT multiplayer_matches_manifest_state_check CHECK (
        (
            state IN ('forming', 'cancelled', 'expired')
            AND manifest_hash IS NULL
            AND started_at IS NULL
        )
        OR
        (state <> 'forming' AND manifest_hash IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multiplayer_lobby_creation_events (
    match_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (match_id),
    KEY multiplayer_lobby_creation_player_index (player_id, created_at),
    CONSTRAINT multiplayer_lobby_creation_match_foreign
        FOREIGN KEY (match_id) REFERENCES multiplayer_matches (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_lobby_creation_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multiplayer_participants (
    match_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    participant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    seat TINYINT UNSIGNED NOT NULL,
    color_index TINYINT UNSIGNED NOT NULL,
    ready TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('joined', 'ready', 'active', 'submitted', 'forfeited') NOT NULL
        DEFAULT 'joined',
    joined_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ON UPDATE CURRENT_TIMESTAMP(3),
    left_at TIMESTAMP(3) NULL,
    PRIMARY KEY (match_id, player_id),
    UNIQUE KEY multiplayer_participants_id_unique (participant_id),
    UNIQUE KEY multiplayer_participants_seat_unique (match_id, seat),
    KEY multiplayer_participants_player_index (player_id, status, joined_at),
    CONSTRAINT multiplayer_participants_match_foreign
        FOREIGN KEY (match_id) REFERENCES multiplayer_matches (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_participants_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_participants_seat_check CHECK (seat < 4),
    CONSTRAINT multiplayer_participants_color_check CHECK (color_index < 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multiplayer_roster_confirmations (
    match_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    roster_hash BINARY(32) NOT NULL,
    coordinator_participant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    confirmed_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (match_id, player_id),
    KEY multiplayer_roster_coordinator_index (coordinator_participant_id),
    CONSTRAINT multiplayer_roster_match_foreign
        FOREIGN KEY (match_id) REFERENCES multiplayer_matches (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_roster_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_roster_coordinator_foreign
        FOREIGN KEY (coordinator_participant_id)
        REFERENCES multiplayer_participants (participant_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multiplayer_submissions (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    match_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    manifest_hash BINARY(32) NOT NULL,
    transcript_hash BINARY(32) NOT NULL,
    event_count INT UNSIGNED NOT NULL,
    proof_json MEDIUMBLOB NOT NULL,
    submitted_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY multiplayer_submissions_player_unique (match_id, player_id),
    KEY multiplayer_submissions_hash_index (match_id, transcript_hash),
    CONSTRAINT multiplayer_submissions_match_foreign
        FOREIGN KEY (match_id) REFERENCES multiplayer_matches (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_submissions_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_submissions_event_count_check CHECK (
        event_count BETWEEN 1 AND 2500
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multiplayer_trace_claims (
    trace_hash BINARY(32) NOT NULL,
    first_match_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    claimed_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (trace_hash),
    UNIQUE KEY multiplayer_trace_match_unique (first_match_id),
    CONSTRAINT multiplayer_trace_match_foreign
        FOREIGN KEY (first_match_id) REFERENCES multiplayer_matches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multiplayer_results (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    match_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    participant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    placement TINYINT UNSIGNED NOT NULL,
    player_count TINYINT UNSIGNED NOT NULL,
    score BIGINT UNSIGNED NOT NULL,
    duration_ms BIGINT UNSIGNED NOT NULL,
    fastest_reaction_ms INT UNSIGNED NULL,
    average_reaction_ms INT UNSIGNED NULL,
    correct_taps INT UNSIGNED NOT NULL,
    miss_count INT UNSIGNED NOT NULL,
    dodge_count INT UNSIGNED NOT NULL,
    godlike_count INT UNSIGNED NOT NULL,
    perfect_count INT UNSIGNED NOT NULL,
    great_count INT UNSIGNED NOT NULL,
    good_count INT UNSIGNED NOT NULL,
    max_multiplier TINYINT UNSIGNED NOT NULL,
    verification_status ENUM(
        'verified',
        'review',
        'quarantined',
        'deleted'
    ) NOT NULL,
    verification_method VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    risk_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    risk_reasons JSON NOT NULL,
    achieved_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY multiplayer_results_match_player_unique (match_id, player_id),
    KEY multiplayer_results_ranking_index (
        season_id,
        verification_status,
        score DESC,
        placement ASC,
        duration_ms DESC,
        correct_taps DESC,
        achieved_at,
        id
    ),
    KEY multiplayer_results_player_best_index (
        player_id,
        verification_status,
        score DESC
    ),
    CONSTRAINT multiplayer_results_match_foreign
        FOREIGN KEY (match_id) REFERENCES multiplayer_matches (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_results_season_foreign
        FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT multiplayer_results_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_results_participant_foreign
        FOREIGN KEY (participant_id) REFERENCES multiplayer_participants (participant_id)
            ON DELETE CASCADE,
    CONSTRAINT multiplayer_results_placement_check CHECK (
        player_count BETWEEN 2 AND 4
        AND placement BETWEEN 1 AND player_count
    ),
    CONSTRAINT multiplayer_results_rating_count_check CHECK (
        godlike_count + perfect_count + great_count + good_count = correct_taps
    ),
    CONSTRAINT multiplayer_results_multiplier_check CHECK (
        max_multiplier BETWEEN 1 AND 5
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
