CREATE TABLE IF NOT EXISTS player_sessions (
    session_auth_hash BINARY(32) NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at TIMESTAMP(3) NOT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (session_auth_hash),
    KEY player_sessions_player_index (player_id, expires_at),
    KEY player_sessions_expiry_index (expires_at),
    CONSTRAINT player_sessions_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
