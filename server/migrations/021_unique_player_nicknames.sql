-- Confirmed public player names are a case- and accent-insensitive namespace
-- under the players table's utf8mb4_unicode_ci collation. Temporary,
-- unconfirmed placeholders are excluded from the unique key.
--
-- Existing confirmed names containing whitespace no longer satisfy the public
-- name policy. Preserve their accounts and progress, but require those players
-- to choose a valid public name again.
UPDATE players
SET nickname_confirmed = 0
WHERE nickname_confirmed = 1
  AND nickname REGEXP '[[:space:]]';

-- Replace every unconfirmed legacy "Player 1234" placeholder with a stable,
-- no-space value. This is idempotent and does not publish or confirm the name.
UPDATE players
SET nickname = CONCAT('Player', LEFT(SHA2(id, 256), 14))
WHERE nickname_confirmed = 0;

SET @speedytapper_nickname_unique_key_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'nickname_unique_key'
);
SET @speedytapper_nickname_unique_key_sql = IF(
    @speedytapper_nickname_unique_key_exists = 0,
    'ALTER TABLE players '
        'ADD COLUMN nickname_unique_key VARCHAR(20) '
        'GENERATED ALWAYS AS (IF(nickname_confirmed = 1, nickname, NULL)) STORED '
        'AFTER nickname_confirmed',
    'DO 1'
);
PREPARE speedytapper_nickname_unique_key_statement
    FROM @speedytapper_nickname_unique_key_sql;
EXECUTE speedytapper_nickname_unique_key_statement;
DEALLOCATE PREPARE speedytapper_nickname_unique_key_statement;

SET @speedytapper_confirmed_nickname_unique_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND INDEX_NAME = 'players_confirmed_nickname_unique'
);
SET @speedytapper_confirmed_nickname_unique_sql = IF(
    @speedytapper_confirmed_nickname_unique_exists = 0,
    -- If historical confirmed names collide under the table collation, this
    -- authoritative ALTER fails with duplicate key
    -- players_confirmed_nickname_unique. Resolve those profiles explicitly and
    -- rerun; never silently choose an owner or merge their data.
    'ALTER TABLE players '
        'ADD UNIQUE KEY players_confirmed_nickname_unique (nickname_unique_key)',
    'DO 1'
);
PREPARE speedytapper_confirmed_nickname_unique_statement
    FROM @speedytapper_confirmed_nickname_unique_sql;
EXECUTE speedytapper_confirmed_nickname_unique_statement;
DEALLOCATE PREPARE speedytapper_confirmed_nickname_unique_statement;
