<?php

declare(strict_types=1);

// Production: copy to ~/.config/speedytapper/config.php outside the web root.
// Local development may copy this to ignored server/config.local.php instead.
return [
    'SPEEDYTAPPER_DB_HOST' => 'localhost',
    'SPEEDYTAPPER_DB_PORT' => '3306',
    'SPEEDYTAPPER_DB_NAME' => '',
    'SPEEDYTAPPER_DB_USER' => '',
    'SPEEDYTAPPER_DB_PASSWORD' => '',
    'SPEEDYTAPPER_GOOGLE_CLIENT_ID' => '',
    'SPEEDYTAPPER_SEASON_ID' => 'season-1',
    'SPEEDYTAPPER_SEASON_NAME' => 'Season 1',
    'SPEEDYTAPPER_STOREKIT_BUNDLE_ID' => 'com.otcsoftware.pimpopom',
    // Both Apple environments are accepted concurrently. The older singular
    // SPEEDYTAPPER_STOREKIT_ENVIRONMENT remains a one-environment fallback.
    'SPEEDYTAPPER_STOREKIT_ENVIRONMENTS' => ['Sandbox', 'Production'],
    'SPEEDYTAPPER_STOREKIT_APP_APPLE_ID' => '6792328590',
    'SPEEDYTAPPER_STOREKIT_PRODUCTS_JSON' => json_encode([
        'com.otcsoftware.pimpopom.coins.50.v1' => [
            'type' => 'consumable', 'coins' => 50, 'capability' => 'ad_free',
        ],
        'com.otcsoftware.pimpopom.coins.100.v1' => [
            'type' => 'consumable', 'coins' => 100, 'capability' => 'ad_free',
        ],
        'com.otcsoftware.pimpopom.coins.500.v1' => [
            'type' => 'consumable', 'coins' => 500, 'capability' => 'ad_free',
        ],
        'com.otcsoftware.pimpopom.coins.1000.v1' => [
            'type' => 'consumable', 'coins' => 1000, 'capability' => 'ad_free',
        ],
        'com.otcsoftware.pimpopom.removeads.lifetime' => [
            'type' => 'non_consumable', 'coins' => 0, 'capability' => 'ad_free',
        ],
    ], JSON_THROW_ON_ERROR),
    // A random secret of at least 32 bytes; keep it stable and outside Git.
    'SPEEDYTAPPER_STOREKIT_RETENTION_HMAC_KEY' => '',
    // Omit SPEEDYTAPPER_STOREKIT_ROOT_CERTIFICATE_PATHS to use the two
    // Apple-published roots bundled with the release artifact.
    // App Store Server API reconciliation (private .p8 file stays outside web root).
    'SPEEDYTAPPER_STOREKIT_ISSUER_ID' => '',
    'SPEEDYTAPPER_STOREKIT_KEY_ID' => '',
    'SPEEDYTAPPER_STOREKIT_PRIVATE_KEY_PATH' => '',
];
