CREATE TABLE IF NOT EXISTS player_identities (
    provider VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_hash BINARY(32) NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    linked_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_authenticated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (provider, subject_hash),
    UNIQUE KEY player_identities_player_provider_unique (player_id, provider),
    KEY player_identities_player_time_index (player_id, last_authenticated_at),
    CONSTRAINT player_identities_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT player_identities_provider_check
        CHECK (provider IN ('google', 'apple'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO player_identities
    (provider, subject_hash, player_id, linked_at, last_authenticated_at)
SELECT
    'google',
    google_subject_hash,
    id,
    created_at,
    last_login_at
FROM players
WHERE google_subject_hash IS NOT NULL
ON DUPLICATE KEY UPDATE
    last_authenticated_at = GREATEST(
        player_identities.last_authenticated_at,
        VALUES(last_authenticated_at)
    );

ALTER TABLE players
    MODIFY COLUMN google_subject_hash BINARY(32) NULL;

CREATE TABLE IF NOT EXISTS player_game_center_bindings (
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    team_player_id_hash BINARY(32) NOT NULL,
    linked_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_verified_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (player_id),
    UNIQUE KEY player_game_center_team_player_unique (team_player_id_hash),
    KEY player_game_center_verified_index (last_verified_at),
    CONSTRAINT player_game_center_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_apple_authorizations (
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'apple',
    subject_hash BINARY(32) NOT NULL,
    refresh_token_ciphertext VARBINARY(4096) NOT NULL,
    refresh_token_iv BINARY(12) NOT NULL,
    refresh_token_tag BINARY(16) NOT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (player_id),
    UNIQUE KEY player_apple_authorizations_subject_unique (provider, subject_hash),
    CONSTRAINT player_apple_authorizations_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE,
    CONSTRAINT player_apple_authorizations_identity_foreign
        FOREIGN KEY (provider, subject_hash)
        REFERENCES player_identities (provider, subject_hash) ON DELETE CASCADE,
    CONSTRAINT player_apple_authorizations_provider_check
        CHECK (provider = 'apple')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_center_assertion_uses (
    assertion_hash BINARY(32) NOT NULL,
    consumed_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    expires_at TIMESTAMP(3) NOT NULL,
    PRIMARY KEY (assertion_hash),
    KEY game_center_assertion_expiry_index (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
