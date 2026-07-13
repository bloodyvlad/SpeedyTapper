<?php

declare(strict_types=1);

// Copy to server/config.local.php on the server and fill in the values there.
// config.local.php is ignored by Git and blocked from HTTP access by .htaccess.
return [
    'SPEEDYTAPPER_DB_HOST' => 'localhost',
    'SPEEDYTAPPER_DB_PORT' => '3306',
    'SPEEDYTAPPER_DB_NAME' => '',
    'SPEEDYTAPPER_DB_USER' => '',
    'SPEEDYTAPPER_DB_PASSWORD' => '',
    'SPEEDYTAPPER_GOOGLE_CLIENT_ID' => '',
    'SPEEDYTAPPER_SEASON_ID' => 'season-1',
    'SPEEDYTAPPER_SEASON_NAME' => 'Season 1',
];
