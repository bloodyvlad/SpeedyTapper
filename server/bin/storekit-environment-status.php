<?php

declare(strict_types=1);

use SpeedyTapper\Config;
use SpeedyTapper\Database;

$root = dirname(__DIR__, 2);
require $root . '/server/autoload.php';

if ($argc !== 2 || $argv[1] !== '--summary') {
    fwrite(STDERR, "Usage: php server/bin/storekit-environment-status.php --summary\n");
    exit(2);
}

$config = Config::load($root);
$database = Database::connect($config);
$statement = $database->prepare(
    "SELECT notification_uuid, apple_notification_uuid, processing_status, processed_at "
    . "FROM storekit_notifications WHERE environment = :environment "
    . "AND notification_type = 'TEST' ORDER BY processed_at DESC LIMIT 1"
);
$state = $database->prepare(
    'SELECT last_notification_check_at, last_transaction_check_at, last_error '
    . 'FROM storekit_reconciliation_state WHERE environment = :environment LIMIT 1'
);
$summary = [];
foreach ($config->acceptedStoreKitEnvironments() as $environment) {
    $statement->execute(['environment' => $environment]);
    $notification = $statement->fetch();
    $state->execute(['environment' => $environment]);
    $reconciliation = $state->fetch();
    $summary[$environment] = [
        'latestTestNotification' => is_array($notification) ? [
            'notificationUUID' => $notification['apple_notification_uuid'],
            'status' => $notification['processing_status'],
            'processedAt' => $notification['processed_at'],
        ] : null,
        'reconciliation' => is_array($reconciliation) ? [
            'lastNotificationCheckAt' => $reconciliation['last_notification_check_at'],
            'lastTransactionCheckAt' => $reconciliation['last_transaction_check_at'],
            'lastError' => $reconciliation['last_error'],
        ] : null,
    ];
}

fwrite(STDOUT, json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
