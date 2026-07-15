ALTER TABLE player_pets
    MODIFY COLUMN acquisition_source ENUM(
        'purchase',
        'legacy_easter_egg',
        'admin_test_grant'
    ) NOT NULL;

INSERT INTO player_pets (
    player_id,
    pet_id,
    price_paid,
    acquisition_source,
    acquired_at
)
SELECT
    admin_role.player_id,
    pet.id,
    0,
    'admin_test_grant',
    UTC_TIMESTAMP(3)
FROM player_roles AS admin_role
CROSS JOIN pets AS pet
WHERE admin_role.role = 'leaderboard_admin'
  AND admin_role.granted_by = 'migration-011'
  AND pet.active = 1
ON DUPLICATE KEY UPDATE
    player_id = VALUES(player_id),
    pet_id = VALUES(pet_id);

INSERT INTO player_themes (
    player_id,
    theme_id,
    price_paid,
    acquired_at
)
SELECT
    admin_role.player_id,
    theme.id,
    0,
    UTC_TIMESTAMP(3)
FROM player_roles AS admin_role
CROSS JOIN themes AS theme
WHERE admin_role.role = 'leaderboard_admin'
  AND admin_role.granted_by = 'migration-011'
  AND theme.active = 1
  AND theme.price_coins > 0
ON DUPLICATE KEY UPDATE
    player_id = VALUES(player_id),
    theme_id = VALUES(theme_id);
