SET @speedytapper_nickname_confirmed_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'nickname_confirmed'
);

SET @speedytapper_nickname_confirmed_sql = IF(
    @speedytapper_nickname_confirmed_exists = 0,
    'ALTER TABLE players ADD COLUMN nickname_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER nickname',
    'DO 1'
);

PREPARE speedytapper_nickname_confirmed_statement FROM @speedytapper_nickname_confirmed_sql;
EXECUTE speedytapper_nickname_confirmed_statement;
DEALLOCATE PREPARE speedytapper_nickname_confirmed_statement;
