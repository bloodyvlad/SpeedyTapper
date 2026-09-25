-- Heart pickups restore lives without resetting cumulative mistakes. Preserve
-- all existing unranked results while widening only their bounded misses field.
-- One ALTER replaces the prior constraint and column type together. Reapplying
-- after an interrupted migration is safe and never rewrites aggregate values.
SET @speedytapper_mp2_result_check_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'multiplayer_v2_result_players'
      AND CONSTRAINT_NAME = 'multiplayer_v2_result_bounds'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @speedytapper_mp2_drop_result_check = IF(
    @speedytapper_mp2_result_check_exists = 0,
    '',
    IF(
        LOCATE('MariaDB', VERSION()) > 0,
        'DROP CONSTRAINT multiplayer_v2_result_bounds, ',
        'DROP CHECK multiplayer_v2_result_bounds, '
    )
);
SET @speedytapper_mp2_result_bounds_sql = CONCAT(
    'ALTER TABLE multiplayer_v2_result_players ',
    @speedytapper_mp2_drop_result_check,
    'MODIFY COLUMN misses SMALLINT UNSIGNED NOT NULL, ',
    'ADD CONSTRAINT multiplayer_v2_result_bounds CHECK (seat <= 3 AND lives <= 3 AND misses <= 1000)'
);
PREPARE speedytapper_mp2_result_bounds_statement FROM @speedytapper_mp2_result_bounds_sql;
EXECUTE speedytapper_mp2_result_bounds_statement;
DEALLOCATE PREPARE speedytapper_mp2_result_bounds_statement;
