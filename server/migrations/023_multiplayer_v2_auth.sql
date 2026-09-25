-- Isolated v2 access credentials. No changes to v1 manifests, results or ranking.
-- Account deletion and logout/rotation revoke both tables through their FKs.
CREATE TABLE IF NOT EXISTS multiplayer_v2_tickets (
    ticket_hash BINARY(32) NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_auth_hash BINARY(32) NOT NULL,
    protocol_version SMALLINT UNSIGNED NOT NULL,
    ruleset_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(3) NOT NULL,
    expires_at TIMESTAMP(3) NOT NULL,
    consumed_at TIMESTAMP(3) NULL,
    PRIMARY KEY (ticket_hash),
    KEY multiplayer_v2_tickets_session_rate (session_auth_hash, created_at),
    KEY multiplayer_v2_tickets_expiry (expires_at),
    CONSTRAINT multiplayer_v2_tickets_player_fk
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_v2_tickets_session_fk
        FOREIGN KEY (session_auth_hash) REFERENCES player_sessions (session_auth_hash) ON DELETE CASCADE,
    CONSTRAINT multiplayer_v2_tickets_protocol CHECK (
        protocol_version = 2 AND ruleset_id = 'multiplayer-shared-arcade-v2'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unranked service-reported alpha aggregates only. No names, credentials, wallet
-- events, v1 result rows, or public/Game Center publication entries are written.
CREATE TABLE IF NOT EXISTS multiplayer_v2_results (
    match_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    protocol_version SMALLINT UNSIGNED NOT NULL,
    ruleset_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    duration_ms INT UNSIGNED NOT NULL,
    created_at TIMESTAMP(3) NOT NULL,
    PRIMARY KEY (match_id),
    KEY multiplayer_v2_results_created (created_at),
    CONSTRAINT multiplayer_v2_results_protocol CHECK (
        protocol_version = 2 AND ruleset_id = 'multiplayer-shared-arcade-v2'
    ),
    CONSTRAINT multiplayer_v2_results_duration CHECK (duration_ms <= 900000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multiplayer_v2_result_players (
    match_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    seat TINYINT UNSIGNED NOT NULL,
    score INT UNSIGNED NOT NULL,
    lives TINYINT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL,
    misses TINYINT UNSIGNED NOT NULL,
    dodges INT UNSIGNED NOT NULL,
    reaction_total_ms INT UNSIGNED NOT NULL,
    fastest_reaction_ms INT UNSIGNED NULL,
    PRIMARY KEY (match_id, player_id),
    UNIQUE KEY multiplayer_v2_result_seat (match_id, seat),
    KEY multiplayer_v2_result_player_lookup (player_id, match_id),
    CONSTRAINT multiplayer_v2_result_match_fk
        FOREIGN KEY (match_id) REFERENCES multiplayer_v2_results (match_id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_v2_result_player_fk
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_v2_result_bounds CHECK (seat <= 3 AND lives <= 3 AND misses <= 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multiplayer_v2_connections (
    binding_hash BINARY(32) NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_auth_hash BINARY(32) NOT NULL,
    protocol_version SMALLINT UNSIGNED NOT NULL,
    ruleset_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(3) NOT NULL,
    expires_at TIMESTAMP(3) NOT NULL,
    PRIMARY KEY (binding_hash),
    KEY multiplayer_v2_connections_session (session_auth_hash, expires_at),
    KEY multiplayer_v2_connections_expiry (expires_at),
    CONSTRAINT multiplayer_v2_connections_player_fk
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT multiplayer_v2_connections_session_fk
        FOREIGN KEY (session_auth_hash) REFERENCES player_sessions (session_auth_hash) ON DELETE CASCADE,
    CONSTRAINT multiplayer_v2_connections_protocol CHECK (
        protocol_version = 2 AND ruleset_id = 'multiplayer-shared-arcade-v2'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
