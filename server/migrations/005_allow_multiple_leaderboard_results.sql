SET @speedytapper_single_result_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leaderboard_entries'
      AND INDEX_NAME = 'leaderboard_player_mode_season_unique'
);

SET @speedytapper_drop_single_result_index_sql = IF(
    @speedytapper_single_result_index_exists > 0,
    'ALTER TABLE leaderboard_entries DROP INDEX leaderboard_player_mode_season_unique',
    'SELECT 1'
);

PREPARE speedytapper_drop_single_result_index_statement
    FROM @speedytapper_drop_single_result_index_sql;
EXECUTE speedytapper_drop_single_result_index_statement;
DEALLOCATE PREPARE speedytapper_drop_single_result_index_statement;

SET @speedytapper_player_mode_lookup_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leaderboard_entries'
      AND INDEX_NAME = 'leaderboard_player_mode_season_index'
);

SET @speedytapper_add_player_mode_lookup_sql = IF(
    @speedytapper_player_mode_lookup_exists = 0,
    'ALTER TABLE leaderboard_entries ADD KEY leaderboard_player_mode_season_index (season_id, player_id, mode)',
    'SELECT 1'
);

PREPARE speedytapper_add_player_mode_lookup_statement
    FROM @speedytapper_add_player_mode_lookup_sql;
EXECUTE speedytapper_add_player_mode_lookup_statement;
DEALLOCATE PREPARE speedytapper_add_player_mode_lookup_statement;
