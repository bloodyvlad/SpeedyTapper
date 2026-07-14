SET @speedytapper_coin_debt_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'coin_debt'
);

SET @speedytapper_coin_debt_sql = IF(
    @speedytapper_coin_debt_exists = 0,
    'ALTER TABLE players ADD COLUMN coin_debt BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER coins',
    'DO 1'
);

PREPARE speedytapper_coin_debt_statement FROM @speedytapper_coin_debt_sql;
EXECUTE speedytapper_coin_debt_statement;
DEALLOCATE PREPARE speedytapper_coin_debt_statement;

ALTER TABLE coin_ledger
    MODIFY COLUMN event_type ENUM(
        'run_credit',
        'migration_credit',
        'pet_purchase',
        'achievement_reward',
        'moderation_reconcile',
        'manual_reconcile'
    ) NOT NULL;

SET @speedytapper_ledger_coin_debt_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'coin_ledger'
      AND COLUMN_NAME = 'coin_debt_after'
);

SET @speedytapper_ledger_coin_debt_sql = IF(
    @speedytapper_ledger_coin_debt_exists = 0,
    'ALTER TABLE coin_ledger ADD COLUMN coin_debt_after BIGINT UNSIGNED NULL AFTER coin_balance_after',
    'DO 1'
);

PREPARE speedytapper_ledger_coin_debt_statement FROM @speedytapper_ledger_coin_debt_sql;
EXECUTE speedytapper_ledger_coin_debt_statement;
DEALLOCATE PREPARE speedytapper_ledger_coin_debt_statement;

INSERT INTO coin_ledger (
    event_id,
    event_key,
    player_id,
    event_type,
    play_ms_delta,
    coin_delta,
    coin_status,
    actor,
    reason,
    created_at
)
SELECT
    UUID(),
    CONCAT('pet:', owned.player_id, ':', owned.pet_id),
    owned.player_id,
    'pet_purchase',
    0,
    -owned.price_paid,
    'eligible',
    'migration-009',
    CONCAT('Imported purchase of ', owned.pet_id, '.'),
    owned.acquired_at
FROM player_pets owned
LEFT JOIN coin_ledger ledger
    ON ledger.event_key = CONCAT('pet:', owned.player_id, ':', owned.pet_id)
WHERE owned.acquisition_source = 'purchase'
  AND ledger.event_id IS NULL;

INSERT INTO coin_ledger (
    event_id,
    event_key,
    player_id,
    event_type,
    play_ms_delta,
    coin_delta,
    coin_status,
    actor,
    reason,
    created_at
)
SELECT
    UUID(),
    CONCAT('achievement:', achievement.player_id, ':', achievement.achievement_key),
    achievement.player_id,
    'achievement_reward',
    0,
    achievement.reward_coins,
    'eligible',
    'migration-009',
    CONCAT('Imported claimed achievement ', achievement.achievement_key, '.'),
    achievement.claimed_at
FROM player_achievements achievement
LEFT JOIN coin_ledger ledger
    ON ledger.event_key = CONCAT(
        'achievement:',
        achievement.player_id,
        ':',
        achievement.achievement_key
    )
WHERE achievement.claimed_at IS NOT NULL
  AND ledger.event_id IS NULL;

UPDATE players player
LEFT JOIN (
    SELECT
        player_id,
        COALESCE(SUM(
            CASE
                WHEN verification_status = 'legacy' THEN duration_ms
                ELSE COALESCE(credited_play_ms, 0)
            END
        ), 0) AS eligible_play_ms,
        COALESCE(SUM(
            CASE
                WHEN verification_status = 'verified' AND coin_status = 'eligible'
                    THEN COALESCE(credited_play_ms, 0)
                ELSE 0
            END
        ), 0) AS verified_play_ms
    FROM completed_runs
    WHERE coin_status IN ('legacy', 'eligible')
    GROUP BY player_id
) run_time ON run_time.player_id = player.id
LEFT JOIN (
    SELECT
        player_id,
        COALESCE(SUM(coin_delta), 0) AS economy_coin_delta,
        COALESCE(SUM(CASE WHEN event_type = 'achievement_reward' THEN coin_delta ELSE 0 END), 0)
            AS achievement_coins
    FROM coin_ledger
    WHERE event_type IN ('pet_purchase', 'achievement_reward')
    GROUP BY player_id
) economy ON economy.player_id = player.id
SET
    player.coins = GREATEST(
        0,
        FLOOR(COALESCE(run_time.eligible_play_ms, 0) / 60000)
            + COALESCE(economy.economy_coin_delta, 0)
    ),
    player.coin_debt = GREATEST(
        0,
        -(
            FLOOR(COALESCE(run_time.eligible_play_ms, 0) / 60000)
                + COALESCE(economy.economy_coin_delta, 0)
        )
    ),
    player.coin_time_remainder_ms = MOD(COALESCE(run_time.eligible_play_ms, 0), 60000),
    player.total_play_ms = COALESCE(run_time.eligible_play_ms, 0),
    player.total_coins_collected = FLOOR(COALESCE(run_time.verified_play_ms, 0) / 60000)
        + COALESCE(economy.achievement_coins, 0);

SET @speedytapper_wallet_exclusive_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND CONSTRAINT_NAME = 'players_wallet_or_debt_not_both'
      AND CONSTRAINT_TYPE = 'CHECK'
);

SET @speedytapper_wallet_exclusive_sql = IF(
    @speedytapper_wallet_exclusive_exists = 0,
    'ALTER TABLE players ADD CONSTRAINT players_wallet_or_debt_not_both CHECK (coins = 0 OR coin_debt = 0)',
    'DO 1'
);

PREPARE speedytapper_wallet_exclusive_statement FROM @speedytapper_wallet_exclusive_sql;
EXECUTE speedytapper_wallet_exclusive_statement;
DEALLOCATE PREPARE speedytapper_wallet_exclusive_statement;
