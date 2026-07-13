SET @speedytapper_leaderboard_security_columns = (
    SELECT CONCAT_WS(', ',
        IF(SUM(COLUMN_NAME = 'verification_status') = 0,
            'ADD COLUMN verification_status ENUM(''legacy'',''verified'',''review'',''quarantined'',''deleted'') NOT NULL DEFAULT ''legacy'' AFTER good_count', NULL),
        IF(SUM(COLUMN_NAME = 'ruleset_id') = 0,
            'ADD COLUMN ruleset_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER verification_status', NULL),
        IF(SUM(COLUMN_NAME = 'proof_version') = 0,
            'ADD COLUMN proof_version SMALLINT UNSIGNED NULL AFTER ruleset_id', NULL),
        IF(SUM(COLUMN_NAME = 'verified_at') = 0,
            'ADD COLUMN verified_at TIMESTAMP(3) NULL AFTER proof_version', NULL),
        IF(SUM(COLUMN_NAME = 'risk_score') = 0,
            'ADD COLUMN risk_score SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER verified_at', NULL),
        IF(SUM(COLUMN_NAME = 'risk_reasons') = 0,
            'ADD COLUMN risk_reasons VARCHAR(500) NULL AFTER risk_score', NULL),
        IF(SUM(COLUMN_NAME = 'moderated_at') = 0,
            'ADD COLUMN moderated_at TIMESTAMP(3) NULL AFTER risk_reasons', NULL),
        IF(SUM(COLUMN_NAME = 'moderated_by') = 0,
            'ADD COLUMN moderated_by VARCHAR(80) NULL AFTER moderated_at', NULL),
        IF(SUM(COLUMN_NAME = 'moderation_reason') = 0,
            'ADD COLUMN moderation_reason VARCHAR(500) NULL AFTER moderated_by', NULL)
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leaderboard_entries'
);

SET @speedytapper_leaderboard_security_sql = IF(
    @speedytapper_leaderboard_security_columns IS NULL
        OR @speedytapper_leaderboard_security_columns = '',
    'DO 1',
    CONCAT('ALTER TABLE leaderboard_entries ', @speedytapper_leaderboard_security_columns)
);

PREPARE speedytapper_leaderboard_security_statement
    FROM @speedytapper_leaderboard_security_sql;
EXECUTE speedytapper_leaderboard_security_statement;
DEALLOCATE PREPARE speedytapper_leaderboard_security_statement;

SET @speedytapper_completed_run_security_columns = (
    SELECT CONCAT_WS(', ',
        IF(SUM(COLUMN_NAME = 'leaderboard_entry_id') = 0,
            'ADD COLUMN leaderboard_entry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER run_id', NULL),
        IF(SUM(COLUMN_NAME = 'verification_status') = 0,
            'ADD COLUMN verification_status ENUM(''legacy'',''verified'',''review'',''quarantined'',''deleted'') NOT NULL DEFAULT ''legacy'' AFTER leaderboard_improved', NULL),
        IF(SUM(COLUMN_NAME = 'coin_status') = 0,
            'ADD COLUMN coin_status ENUM(''legacy'',''eligible'',''withheld'',''revoked'') NOT NULL DEFAULT ''legacy'' AFTER verification_status', NULL),
        IF(SUM(COLUMN_NAME = 'ruleset_id') = 0,
            'ADD COLUMN ruleset_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER coin_status', NULL),
        IF(SUM(COLUMN_NAME = 'proof_version') = 0,
            'ADD COLUMN proof_version SMALLINT UNSIGNED NULL AFTER ruleset_id', NULL),
        IF(SUM(COLUMN_NAME = 'verified_at') = 0,
            'ADD COLUMN verified_at TIMESTAMP(3) NULL AFTER proof_version', NULL),
        IF(SUM(COLUMN_NAME = 'server_elapsed_ms') = 0,
            'ADD COLUMN server_elapsed_ms BIGINT UNSIGNED NULL AFTER verified_at', NULL),
        IF(SUM(COLUMN_NAME = 'credited_play_ms') = 0,
            'ADD COLUMN credited_play_ms BIGINT UNSIGNED NULL AFTER server_elapsed_ms', NULL),
        IF(SUM(COLUMN_NAME = 'miss_count') = 0,
            'ADD COLUMN miss_count INT UNSIGNED NULL AFTER credited_play_ms', NULL),
        IF(SUM(COLUMN_NAME = 'risk_score') = 0,
            'ADD COLUMN risk_score SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER miss_count', NULL),
        IF(SUM(COLUMN_NAME = 'risk_reasons') = 0,
            'ADD COLUMN risk_reasons VARCHAR(500) NULL AFTER risk_score', NULL),
        IF(SUM(COLUMN_NAME = 'moderated_at') = 0,
            'ADD COLUMN moderated_at TIMESTAMP(3) NULL AFTER risk_reasons', NULL),
        IF(SUM(COLUMN_NAME = 'moderated_by') = 0,
            'ADD COLUMN moderated_by VARCHAR(80) NULL AFTER moderated_at', NULL),
        IF(SUM(COLUMN_NAME = 'moderation_reason') = 0,
            'ADD COLUMN moderation_reason VARCHAR(500) NULL AFTER moderated_by', NULL)
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'completed_runs'
);

SET @speedytapper_completed_run_security_sql = IF(
    @speedytapper_completed_run_security_columns IS NULL
        OR @speedytapper_completed_run_security_columns = '',
    'DO 1',
    CONCAT('ALTER TABLE completed_runs ', @speedytapper_completed_run_security_columns)
);

PREPARE speedytapper_completed_run_security_statement
    FROM @speedytapper_completed_run_security_sql;
EXECUTE speedytapper_completed_run_security_statement;
DEALLOCATE PREPARE speedytapper_completed_run_security_statement;

UPDATE completed_runs
SET credited_play_ms = CASE
    WHEN verification_status = 'legacy' THEN duration_ms
    ELSE LEAST(duration_ms, COALESCE(server_elapsed_ms, duration_ms))
END
WHERE credited_play_ms IS NULL;

CREATE TABLE IF NOT EXISTS run_attempts (
    run_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_binding_hash BINARY(32) NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mode ENUM('normal', 'zen') NOT NULL,
    build_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ruleset_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proof_version SMALLINT UNSIGNED NOT NULL,
    status ENUM('issued','submitted','completed','rejected','expired','abandoned') NOT NULL DEFAULT 'issued',
    started_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    expires_at TIMESTAMP(3) NOT NULL,
    submitted_at TIMESTAMP(3) NULL,
    completed_at TIMESTAMP(3) NULL,
    server_elapsed_ms BIGINT UNSIGNED NULL,
    proof_hash BINARY(32) NULL,
    risk_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    risk_reasons VARCHAR(500) NULL,
    submission_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    rejection_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (run_id),
    KEY run_attempts_session_status_index (session_binding_hash, status, started_at),
    KEY run_attempts_player_time_index (player_id, started_at),
    KEY run_attempts_player_submission_index (player_id, submitted_at, run_id),
    KEY run_attempts_expiry_index (status, expires_at),
    KEY run_attempts_status_updated_index (status, updated_at, run_id),
    CONSTRAINT run_attempts_player_foreign
        FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE RESTRICT,
    CONSTRAINT run_attempts_time_order CHECK (expires_at > started_at),
    CONSTRAINT run_attempts_risk_range CHECK (risk_score <= 1000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @speedytapper_attempt_submission_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'run_attempts'
      AND INDEX_NAME = 'run_attempts_player_submission_index'
);

SET @speedytapper_attempt_submission_index_sql = IF(
    @speedytapper_attempt_submission_index_exists = 0,
    'ALTER TABLE run_attempts ADD KEY run_attempts_player_submission_index (player_id, submitted_at, run_id)',
    'DO 1'
);

PREPARE speedytapper_attempt_submission_index_statement
    FROM @speedytapper_attempt_submission_index_sql;
EXECUTE speedytapper_attempt_submission_index_statement;
DEALLOCATE PREPARE speedytapper_attempt_submission_index_statement;

SET @speedytapper_attempt_cleanup_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'run_attempts'
      AND INDEX_NAME = 'run_attempts_status_updated_index'
);

SET @speedytapper_attempt_cleanup_index_sql = IF(
    @speedytapper_attempt_cleanup_index_exists = 0,
    'ALTER TABLE run_attempts ADD KEY run_attempts_status_updated_index (status, updated_at, run_id)',
    'DO 1'
);

PREPARE speedytapper_attempt_cleanup_index_statement
    FROM @speedytapper_attempt_cleanup_index_sql;
EXECUTE speedytapper_attempt_cleanup_index_statement;
DEALLOCATE PREPARE speedytapper_attempt_cleanup_index_statement;

CREATE TABLE IF NOT EXISTS run_trace_claims (
    trace_hash BINARY(32) NOT NULL,
    first_run_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    claimed_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (trace_hash),
    UNIQUE KEY run_trace_claims_run_unique (first_run_id),
    CONSTRAINT run_trace_claims_attempt_foreign
        FOREIGN KEY (first_run_id) REFERENCES run_attempts (run_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS run_proofs (
    run_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proof_version SMALLINT UNSIGNED NOT NULL,
    event_count INT UNSIGNED NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    trace_hash BINARY(32) NOT NULL,
    proof_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    validation_status ENUM('verified','rejected') NOT NULL,
    validation_reason VARCHAR(500) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    validated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (run_id),
    KEY run_proofs_validation_index (validation_status, validated_at),
    KEY run_proofs_trace_index (trace_hash, validation_status),
    CONSTRAINT run_proofs_attempt_foreign
        FOREIGN KEY (run_id) REFERENCES run_attempts (run_id) ON DELETE CASCADE,
    CONSTRAINT run_proofs_json_valid CHECK (JSON_VALID(proof_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leaderboard_moderation_events (
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    leaderboard_entry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    completed_run_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action ENUM('approve','reject','quarantine','restore','delete','reconcile') NOT NULL,
    from_status ENUM('legacy','verified','review','quarantined','deleted') NOT NULL,
    to_status ENUM('legacy','verified','review','quarantined','deleted') NOT NULL,
    from_coin_status ENUM('legacy','eligible','withheld','revoked') NULL,
    to_coin_status ENUM('legacy','eligible','withheld','revoked') NULL,
    actor VARCHAR(80) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    details_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (event_id),
    KEY moderation_entry_time_index (leaderboard_entry_id, created_at),
    KEY moderation_player_time_index (player_id, created_at),
    CONSTRAINT moderation_details_json_valid CHECK (
        details_json IS NULL OR JSON_VALID(details_json)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coin_ledger (
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_type ENUM('run_credit','migration_credit','moderation_reconcile','manual_reconcile') NOT NULL,
    play_ms_delta BIGINT NOT NULL,
    coin_delta BIGINT NOT NULL,
    remainder_before_ms INT UNSIGNED NULL,
    remainder_after_ms INT UNSIGNED NULL,
    coin_balance_after BIGINT UNSIGNED NULL,
    total_play_ms_after BIGINT UNSIGNED NULL,
    coin_status ENUM('legacy','eligible','withheld','revoked') NULL,
    actor VARCHAR(80) NULL,
    reason VARCHAR(500) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (event_id),
    UNIQUE KEY coin_ledger_event_key_unique (event_key),
    KEY coin_ledger_player_time_index (player_id, created_at),
    KEY coin_ledger_run_index (run_id, created_at),
    CONSTRAINT coin_ledger_remainder_before_range CHECK (
        remainder_before_ms IS NULL OR remainder_before_ms < 60000
    ),
    CONSTRAINT coin_ledger_remainder_after_range CHECK (
        remainder_after_ms IS NULL OR remainder_after_ms < 60000
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE completed_runs c
INNER JOIN leaderboard_entries l
    ON l.id = c.run_id
   AND l.player_id = c.player_id
   AND l.mode = c.mode
   AND l.score = c.score
   AND l.duration_ms = c.duration_ms
SET c.leaderboard_entry_id = l.id
WHERE c.leaderboard_entry_id IS NULL;

SET @speedytapper_completed_run_entry_unique_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'completed_runs'
      AND INDEX_NAME = 'completed_runs_leaderboard_entry_unique'
);

SET @speedytapper_completed_run_entry_unique_sql = IF(
    @speedytapper_completed_run_entry_unique_exists = 0,
    'ALTER TABLE completed_runs ADD UNIQUE KEY completed_runs_leaderboard_entry_unique (leaderboard_entry_id)',
    'DO 1'
);

PREPARE speedytapper_completed_run_entry_unique_statement
    FROM @speedytapper_completed_run_entry_unique_sql;
EXECUTE speedytapper_completed_run_entry_unique_statement;
DEALLOCATE PREPARE speedytapper_completed_run_entry_unique_statement;

SET @speedytapper_completed_run_entry_foreign_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'completed_runs'
      AND CONSTRAINT_NAME = 'completed_runs_leaderboard_entry_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @speedytapper_completed_run_entry_foreign_sql = IF(
    @speedytapper_completed_run_entry_foreign_exists = 0,
    'ALTER TABLE completed_runs ADD CONSTRAINT completed_runs_leaderboard_entry_foreign FOREIGN KEY (leaderboard_entry_id) REFERENCES leaderboard_entries (id) ON DELETE SET NULL',
    'DO 1'
);

PREPARE speedytapper_completed_run_entry_foreign_statement
    FROM @speedytapper_completed_run_entry_foreign_sql;
EXECUTE speedytapper_completed_run_entry_foreign_statement;
DEALLOCATE PREPARE speedytapper_completed_run_entry_foreign_statement;

INSERT INTO coin_ledger (
    event_id,
    event_key,
    player_id,
    run_id,
    event_type,
    play_ms_delta,
    coin_delta,
    coin_status,
    actor,
    reason,
    created_at
)
SELECT
    c.run_id,
    CONCAT('migration:', c.run_id),
    c.player_id,
    c.run_id,
    'migration_credit',
    c.duration_ms,
    c.coins_awarded,
    c.coin_status,
    'migration-006',
    'Imported the original immutable completed-run credit.',
    c.completed_at
FROM completed_runs c
LEFT JOIN coin_ledger ledger ON ledger.event_key = CONCAT('migration:', c.run_id)
WHERE ledger.event_id IS NULL;

SET @speedytapper_verified_ranking_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leaderboard_entries'
      AND INDEX_NAME = 'leaderboard_verified_ranking_index'
);

SET @speedytapper_verified_ranking_index_sql = IF(
    @speedytapper_verified_ranking_index_exists = 0,
    'ALTER TABLE leaderboard_entries ADD KEY leaderboard_verified_ranking_index (season_id, mode, verification_status, score DESC, duration_ms DESC, correct_taps DESC, achieved_at, id)',
    'DO 1'
);

PREPARE speedytapper_verified_ranking_index_statement
    FROM @speedytapper_verified_ranking_index_sql;
EXECUTE speedytapper_verified_ranking_index_statement;
DEALLOCATE PREPARE speedytapper_verified_ranking_index_statement;

SET @speedytapper_moderation_lookup_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leaderboard_entries'
      AND INDEX_NAME = 'leaderboard_moderation_lookup_index'
);

SET @speedytapper_moderation_lookup_index_sql = IF(
    @speedytapper_moderation_lookup_index_exists = 0,
    'ALTER TABLE leaderboard_entries ADD KEY leaderboard_moderation_lookup_index (verification_status, achieved_at, id)',
    'DO 1'
);

PREPARE speedytapper_moderation_lookup_index_statement
    FROM @speedytapper_moderation_lookup_index_sql;
EXECUTE speedytapper_moderation_lookup_index_statement;
DEALLOCATE PREPARE speedytapper_moderation_lookup_index_statement;

SET @speedytapper_completed_run_coin_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'completed_runs'
      AND INDEX_NAME = 'completed_runs_player_coin_time_index'
);

SET @speedytapper_completed_run_coin_index_sql = IF(
    @speedytapper_completed_run_coin_index_exists = 0,
    'ALTER TABLE completed_runs ADD KEY completed_runs_player_coin_time_index (player_id, coin_status, completed_at, run_id)',
    'DO 1'
);

PREPARE speedytapper_completed_run_coin_index_statement
    FROM @speedytapper_completed_run_coin_index_sql;
EXECUTE speedytapper_completed_run_coin_index_statement;
DEALLOCATE PREPARE speedytapper_completed_run_coin_index_statement;
