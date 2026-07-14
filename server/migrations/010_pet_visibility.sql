SET @speedytapper_pet_visibility_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_pet_selection'
      AND COLUMN_NAME = 'is_visible'
);

SET @speedytapper_pet_visibility_sql = IF(
    @speedytapper_pet_visibility_exists = 0,
    'ALTER TABLE player_pet_selection ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER pet_id',
    'DO 1'
);

PREPARE speedytapper_pet_visibility_statement FROM @speedytapper_pet_visibility_sql;
EXECUTE speedytapper_pet_visibility_statement;
DEALLOCATE PREPARE speedytapper_pet_visibility_statement;
