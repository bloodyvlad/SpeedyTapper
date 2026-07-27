INSERT INTO seasons (id, name) VALUES ('season-1', 'Season 1');

INSERT INTO players (
    id,
    nickname,
    nickname_confirmed,
    coins,
    earned_coins,
    purchased_coins,
    total_coins_collected,
    total_play_ms
) VALUES (
    '11111111-1111-4111-8111-111111111111',
    'ResetTester',
    1,
    45,
    5,
    40,
    45,
    60000
);

INSERT INTO player_identities (provider, subject_hash, player_id)
VALUES
    ('google', UNHEX(SHA2('google-subject', 256)), '11111111-1111-4111-8111-111111111111'),
    ('apple', UNHEX(SHA2('apple-subject', 256)), '11111111-1111-4111-8111-111111111111');

INSERT INTO player_apple_authorizations (
    player_id,
    subject_hash,
    refresh_token_ciphertext,
    refresh_token_iv,
    refresh_token_tag
) VALUES (
    '11111111-1111-4111-8111-111111111111',
    UNHEX(SHA2('apple-subject', 256)),
    'ciphertext',
    UNHEX(REPEAT('11', 12)),
    UNHEX(REPEAT('22', 16))
);

INSERT INTO player_sessions (session_auth_hash, player_id, expires_at)
VALUES (
    UNHEX(SHA2('session', 256)),
    '11111111-1111-4111-8111-111111111111',
    UTC_TIMESTAMP(3) + INTERVAL 1 DAY
);

INSERT INTO player_roles (player_id, role, granted_by, reason)
VALUES (
    '11111111-1111-4111-8111-111111111111',
    'leaderboard_admin',
    'reset-test',
    'Integration fixture'
);

INSERT INTO leaderboard_entries (
    id,
    season_id,
    player_id,
    mode,
    score,
    duration_ms,
    correct_taps,
    dodge_count,
    godlike_count,
    perfect_count,
    great_count,
    good_count,
    verification_status
) VALUES (
    '22222222-2222-4222-8222-222222222222',
    'season-1',
    '11111111-1111-4111-8111-111111111111',
    'normal',
    12345,
    60000,
    4,
    0,
    1,
    1,
    1,
    1,
    'verified'
);

INSERT INTO run_attempts (
    run_id,
    session_binding_hash,
    player_id,
    mode,
    build_id,
    ruleset_id,
    proof_version,
    status,
    expires_at
) VALUES (
    '33333333-3333-4333-8333-333333333333',
    UNHEX(SHA2('run-session', 256)),
    '11111111-1111-4111-8111-111111111111',
    'normal',
    '20260727-1',
    'reaction-proof-v2',
    1,
    'completed',
    UTC_TIMESTAMP(3) + INTERVAL 5 MINUTE
);

INSERT INTO run_proofs (
    run_id,
    proof_version,
    event_count,
    payload_hash,
    trace_hash,
    proof_json,
    validation_status
) VALUES (
    '33333333-3333-4333-8333-333333333333',
    1,
    1,
    UNHEX(SHA2('proof-payload', 256)),
    UNHEX(SHA2('proof-trace', 256)),
    '{}',
    'verified'
);

INSERT INTO run_trace_claims (trace_hash, first_run_id)
VALUES (
    UNHEX(SHA2('proof-trace', 256)),
    '33333333-3333-4333-8333-333333333333'
);

INSERT INTO completed_runs (
    run_id,
    leaderboard_entry_id,
    player_id,
    payload_hash,
    mode,
    score,
    duration_ms,
    reaction_base_points,
    multiplier_bonus_points,
    max_multiplier,
    multiplier_1_hits,
    multiplier_2_hits,
    multiplier_3_hits,
    multiplier_4_hits,
    multiplier_5_hits,
    multiplier_1_base_points,
    multiplier_2_base_points,
    multiplier_3_base_points,
    multiplier_4_base_points,
    multiplier_5_base_points,
    coins_awarded,
    leaderboard_improved,
    verification_status,
    coin_status
) VALUES (
    '33333333-3333-4333-8333-333333333333',
    '22222222-2222-4222-8222-222222222222',
    '11111111-1111-4111-8111-111111111111',
    UNHEX(SHA2('completed-payload', 256)),
    'normal',
    12345,
    60000,
    1000,
    11345,
    2,
    4,
    0,
    0,
    0,
    0,
    1000,
    0,
    0,
    0,
    0,
    1,
    1,
    'verified',
    'eligible'
);

INSERT INTO leaderboard_moderation_events (
    event_id,
    leaderboard_entry_id,
    completed_run_id,
    player_id,
    action,
    from_status,
    to_status,
    actor,
    reason
) VALUES (
    '44444444-4444-4444-8444-444444444441',
    '22222222-2222-4222-8222-222222222222',
    '33333333-3333-4333-8333-333333333333',
    '11111111-1111-4111-8111-111111111111',
    'approve',
    'review',
    'verified',
    'admin:test',
    'Integration fixture'
);

INSERT INTO coin_ledger (
    event_id,
    event_key,
    player_id,
    event_type,
    play_ms_delta,
    coin_delta,
    earned_delta,
    purchased_delta,
    coin_balance_after,
    earned_balance_after,
    purchased_balance_after,
    coin_debt_after,
    earned_debt_after,
    refund_debt_after,
    total_play_ms_after,
    coin_status
) VALUES (
    '44444444-4444-4444-8444-444444444442',
    'reset-test:pet-purchase',
    '11111111-1111-4111-8111-111111111111',
    'pet_purchase',
    0,
    -10,
    -5,
    -5,
    45,
    5,
    40,
    0,
    0,
    0,
    60000,
    'eligible'
);

INSERT INTO player_achievements (player_id, achievement_key, reward_coins)
VALUES ('11111111-1111-4111-8111-111111111111', 'complete_arcade', 1);

INSERT INTO player_pets (
    player_id,
    pet_id,
    price_paid,
    acquisition_source,
    purchase_event_id
) VALUES (
    '11111111-1111-4111-8111-111111111111',
    'foka',
    10,
    'purchase',
    '44444444-4444-4444-8444-444444444442'
);

INSERT INTO player_pet_selection (player_id, pet_id, is_visible)
VALUES ('11111111-1111-4111-8111-111111111111', 'foka', 1);

INSERT INTO player_themes (player_id, theme_id, price_paid, purchase_event_id)
VALUES (
    '11111111-1111-4111-8111-111111111111',
    'light',
    50,
    '44444444-4444-4444-8444-444444444442'
);

INSERT INTO player_theme_selection (player_id, theme_id)
VALUES ('11111111-1111-4111-8111-111111111111', 'light');

INSERT INTO account_reward_resets (
    reset_id,
    trigger_entry_id,
    player_id,
    actor_player_id,
    from_generation,
    to_generation,
    coins_removed,
    debt_cleared,
    remainder_removed_ms,
    total_play_removed_ms,
    total_collected_removed,
    pets_removed,
    pet_ids_json,
    themes_removed,
    theme_ids_json,
    reason
) VALUES (
    '55555555-5555-4555-8555-555555555555',
    '22222222-2222-4222-8222-222222222222',
    '11111111-1111-4111-8111-111111111111',
    '11111111-1111-4111-8111-111111111111',
    0,
    1,
    0,
    0,
    0,
    0,
    0,
    0,
    '[]',
    0,
    '[]',
    'Integration fixture'
);

INSERT INTO player_game_center_bindings (
    player_id,
    team_player_id_hash,
    game_player_id_hash,
    game_player_id_ciphertext,
    game_player_id_iv,
    game_player_id_tag,
    publication_enabled_at
) VALUES (
    '11111111-1111-4111-8111-111111111111',
    UNHEX(SHA2('team-player', 256)),
    UNHEX(SHA2('game-player', 256)),
    'encrypted-game-player',
    UNHEX(REPEAT('33', 12)),
    UNHEX(REPEAT('44', 16)),
    UTC_TIMESTAMP(3)
);

INSERT INTO game_center_publication_outbox (
    id,
    player_id,
    publication_kind,
    vendor_identifier,
    pre_released,
    desired_value
) VALUES (
    '66666666-6666-4666-8666-666666666666',
    '11111111-1111-4111-8111-111111111111',
    'leaderboard',
    'com.otcsoftware.pimpopom.arcade.verified',
    1,
    12345
);

INSERT INTO game_center_assertion_uses (assertion_hash, expires_at)
VALUES (UNHEX(SHA2('game-center-assertion', 256)), UTC_TIMESTAMP(3) + INTERVAL 5 MINUTE);

INSERT INTO player_storekit_bindings (player_id, app_account_token)
VALUES (
    '11111111-1111-4111-8111-111111111111',
    '77777777-7777-4777-8777-777777777777'
);

INSERT INTO storekit_transactions (
    transaction_id,
    apple_transaction_id,
    original_transaction_id,
    app_transaction_id,
    app_transaction_pseudonym,
    player_id,
    account_token_pseudonym,
    product_id,
    product_type,
    ownership_type,
    environment,
    bundle_id,
    signed_quantity,
    purchase_date_ms,
    signed_date_ms,
    lifecycle_signed_date_ms,
    credited_coins,
    payload_hash
) VALUES
(
    'Sandbox:tx-1',
    'tx-1',
    'original-tx-1',
    'app-tx-1',
    UNHEX(REPEAT('55', 32)),
    '11111111-1111-4111-8111-111111111111',
    UNHEX(REPEAT('66', 32)),
    'com.otcsoftware.pimpopom.coins.50.v1',
    'consumable',
    'PURCHASED',
    'Sandbox',
    'com.otcsoftware.pimpopom',
    1,
    1,
    1,
    1,
    50,
    UNHEX(SHA2('transaction-1', 256))
),
(
    'Sandbox:tx-orphan',
    'tx-orphan',
    'original-tx-orphan',
    'app-tx-orphan',
    UNHEX(REPEAT('77', 32)),
    NULL,
    UNHEX(REPEAT('88', 32)),
    'com.otcsoftware.pimpopom.removeads.lifetime',
    'non_consumable',
    'PURCHASED',
    'Sandbox',
    'com.otcsoftware.pimpopom',
    1,
    2,
    2,
    2,
    0,
    UNHEX(SHA2('transaction-orphan', 256))
);

INSERT INTO purchased_coin_lots (
    transaction_id,
    player_id,
    gross_coins,
    available_coins,
    spent_coins
) VALUES (
    'Sandbox:tx-1',
    '11111111-1111-4111-8111-111111111111',
    50,
    40,
    10
);

INSERT INTO player_entitlement_sources (
    source_id,
    player_id,
    capability,
    source_type,
    source_transaction_id
) VALUES (
    '88888888-8888-4888-8888-888888888881',
    '11111111-1111-4111-8111-111111111111',
    'ad_free',
    'coin_purchase',
    'Sandbox:tx-1'
);

INSERT INTO player_storekit_family_bindings (
    environment,
    app_transaction_pseudonym,
    player_id
) VALUES (
    'Sandbox',
    UNHEX(REPEAT('99', 32)),
    '11111111-1111-4111-8111-111111111111'
);

INSERT INTO coin_spend_allocations (
    allocation_id,
    spend_event_id,
    player_id,
    source,
    lot_transaction_id,
    amount,
    purpose,
    spend_reference_pseudonym
) VALUES
(
    '88888888-8888-4888-8888-888888888882',
    '44444444-4444-4444-8444-444444444442',
    '11111111-1111-4111-8111-111111111111',
    'earned',
    NULL,
    5,
    'pet_purchase',
    UNHEX(REPEAT('aa', 32))
),
(
    '88888888-8888-4888-8888-888888888883',
    '44444444-4444-4444-8444-444444444442',
    '11111111-1111-4111-8111-111111111111',
    'purchased',
    'Sandbox:tx-1',
    5,
    'pet_purchase',
    UNHEX(REPEAT('bb', 32))
);

INSERT INTO storekit_cosmetic_restore_debts (
    debt_id,
    refund_transaction_id,
    player_id,
    purchase_event_id,
    item_type,
    item_id,
    amount
) VALUES (
    '88888888-8888-4888-8888-888888888884',
    'Sandbox:tx-1',
    '11111111-1111-4111-8111-111111111111',
    '44444444-4444-4444-8444-444444444442',
    'pet',
    'foka',
    1
);

INSERT INTO storekit_refund_debt_allocations (
    allocation_id,
    player_id,
    source_type,
    source_reference,
    refund_transaction_id,
    amount
) VALUES (
    '88888888-8888-4888-8888-888888888885',
    '11111111-1111-4111-8111-111111111111',
    'earned_credit',
    'raw-run-reference',
    'Sandbox:tx-1',
    1
);

INSERT INTO storekit_refund_cosmetics (
    revocation_id,
    refund_transaction_id,
    player_id,
    purchase_event_id,
    refund_cycle,
    item_type,
    item_id,
    price_paid
) VALUES (
    '88888888-8888-4888-8888-888888888886',
    'Sandbox:tx-1',
    '11111111-1111-4111-8111-111111111111',
    '44444444-4444-4444-8444-444444444442',
    1,
    'pet',
    'foka',
    10
);

INSERT INTO storekit_transaction_observations (
    observation_id,
    transaction_id,
    observed_state,
    signed_date_ms,
    payload_hash
) VALUES (
    '88888888-8888-4888-8888-888888888887',
    'Sandbox:tx-1',
    'active',
    1,
    UNHEX(SHA2('observation', 256))
);

INSERT INTO storekit_notifications (
    notification_uuid,
    apple_notification_uuid,
    transaction_id,
    notification_type,
    environment,
    signed_date_ms,
    payload_hash,
    processing_status
) VALUES (
    'Sandbox:99999999-9999-4999-8999-999999999999',
    '99999999-9999-4999-8999-999999999999',
    'Sandbox:tx-1',
    'DID_RENEW',
    'Sandbox',
    1,
    UNHEX(SHA2('notification', 256)),
    'processed'
);

INSERT INTO storekit_reconciliation_state (
    environment,
    last_transaction_cursor
) VALUES ('Sandbox', 'cursor-1')
ON DUPLICATE KEY UPDATE last_transaction_cursor = VALUES(last_transaction_cursor);
