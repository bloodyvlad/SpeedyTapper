<?php

declare(strict_types=1);

use SpeedyTapper\AppStoreNotificationService;
use SpeedyTapper\AppStoreServerApiClient;
use SpeedyTapper\AppleJwsVerifier;
use SpeedyTapper\CoinWalletRepository;
use SpeedyTapper\Config;
use SpeedyTapper\Database;
use SpeedyTapper\StoreKitAccountRepository;
use SpeedyTapper\StoreKitProductCatalog;
use SpeedyTapper\StoreKitService;

$root = dirname(__DIR__, 2);
require $root . '/server/autoload.php';
$composer = $root . '/vendor/autoload.php';
if (is_file($composer)) require $composer;

$limit = 100;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=([1-9][0-9]{0,3})$/D', $argument, $matches) === 1) {
        $limit = min(500, (int) $matches[1]);
        continue;
    }
    fwrite(STDERR, "Usage: php server/bin/reconcile-storekit.php [--limit=100]\n");
    exit(2);
}

$config = Config::load($root);
if (!$config->storeKitServerApiIsConfigured()) {
    fwrite(STDERR, "App Store Server API reconciliation is not configured.\n");
    exit(2);
}
$database = Database::connect($config);
$verifier = AppleJwsVerifier::fromPemFiles($config->storeKitRootCertificatePaths);
$accounts = new StoreKitAccountRepository($database, $config->storeKitRetentionHmacKey ?? '');
$storeKit = new StoreKitService(
    $database,
    $config,
    new StoreKitProductCatalog($config->storeKitProducts),
    $verifier,
    $accounts,
    new CoinWalletRepository($database),
);
$notifications = new AppStoreNotificationService($database, $config, $verifier, $storeKit);
$failures = 0;

foreach ($config->acceptedStoreKitEnvironments() as $environment) {
    $lockName = 'speedytapper-storekit-reconcile-' . strtolower($environment);
    $lockStatement = $database->prepare('SELECT GET_LOCK(:lock_name, 0)');
    $lockStatement->execute(['lock_name' => $lockName]);
    if ((int) $lockStatement->fetchColumn() !== 1) {
        fwrite(STDOUT, "Another {$environment} StoreKit reconciliation is already running.\n");
        continue;
    }

    try {
        $apple = new AppStoreServerApiClient($config, $environment);
        $nowMs = (int) floor(microtime(true) * 1000);
        $notificationBoundary = gmdate('Y-m-d H:i:s', intdiv($nowMs, 1000))
            . sprintf('.%03d', $nowMs % 1000);
        $state = $database->prepare(
            'SELECT last_notification_check_at FROM storekit_reconciliation_state '
            . 'WHERE environment = :environment'
        );
        $state->execute(['environment' => $environment]);
        $last = $state->fetchColumn();
        $startMs = is_string($last)
            ? max(1, ((new DateTimeImmutable($last, new DateTimeZone('UTC')))->getTimestamp() * 1000) - 60_000)
            : $nowMs - 86_400_000;
        $pagination = null;
        $notificationCount = 0;
        $historyComplete = false;
        for ($page = 0; $page < 20; $page++) {
            $history = $apple->notificationHistory($startMs, $nowMs, $pagination);
            foreach ($history['signedPayloads'] as $signedPayload) {
                $notifications->receive($signedPayload);
                $notificationCount++;
            }
            if (!$history['hasMore'] || $history['paginationToken'] === null) {
                $historyComplete = true;
                break;
            }
            $pagination = $history['paginationToken'];
        }
        if (!$historyComplete) {
            throw new RuntimeException('Apple notification history exceeded the safe page limit.');
        }

        $cursorStatement = $database->prepare(
            'SELECT last_transaction_cursor FROM storekit_reconciliation_state '
            . 'WHERE environment = :environment'
        );
        $cursorStatement->execute(['environment' => $environment]);
        $cursor = $cursorStatement->fetchColumn();
        $cursor = is_string($cursor) ? $cursor : '';
        $transactions = $database->prepare(
            'SELECT apple_transaction_id, status FROM storekit_transactions '
            . 'WHERE environment = :environment AND apple_transaction_id > :cursor '
            . 'ORDER BY apple_transaction_id LIMIT :limit'
        );
        $transactions->bindValue(':environment', $environment);
        $transactions->bindValue(':cursor', $cursor);
        $transactions->bindValue(':limit', $limit, PDO::PARAM_INT);
        $transactions->execute();
        $rows = $transactions->fetchAll();
        $transactionCount = 0;
        $lastProcessed = null;
        foreach ($rows as $stored) {
            $appleTransactionId = (string) $stored['apple_transaction_id'];
            $signed = $apple->getTransactionInfo($appleTransactionId);
            $verified = $verifier->verify($signed);
            $type = isset($verified['revocationDate'])
                ? 'REFUND'
                : (in_array($stored['status'], ['refunded', 'revoked'], true)
                    ? 'REFUND_REVERSED'
                    : 'ONE_TIME_CHARGE');
            $storeKit->processNotificationTransaction($signed, $type, null, $environment);
            $transactionCount++;
            $lastProcessed = $appleTransactionId;
        }
        $nextCursor = count($rows) < $limit ? null : $lastProcessed;

        $upsert = $database->prepare(
            'INSERT INTO storekit_reconciliation_state '
            . '(environment, last_notification_check_at, last_transaction_check_at, '
            . 'last_transaction_cursor, last_error) '
            . 'VALUES (:environment, :notification_boundary, UTC_TIMESTAMP(3), :transaction_cursor, NULL) '
            . 'ON DUPLICATE KEY UPDATE last_notification_check_at = VALUES(last_notification_check_at), '
            . 'last_transaction_check_at = VALUES(last_transaction_check_at), '
            . 'last_transaction_cursor = VALUES(last_transaction_cursor), last_error = NULL'
        );
        $upsert->execute([
            'environment' => $environment,
            'notification_boundary' => $notificationBoundary,
            'transaction_cursor' => $nextCursor,
        ]);
        fwrite(STDOUT, sprintf(
            "%s StoreKit reconciliation complete: %d notifications, %d transactions.\n",
            $environment,
            $notificationCount,
            $transactionCount,
        ));
    } catch (Throwable $error) {
        $failures++;
        $message = mb_strcut($error->getMessage(), 0, 500, 'UTF-8');
        try {
            $failure = $database->prepare(
                'INSERT INTO storekit_reconciliation_state (environment, last_error) '
                . 'VALUES (:environment, :last_error) '
                . 'ON DUPLICATE KEY UPDATE last_error = VALUES(last_error)'
            );
            $failure->execute(['environment' => $environment, 'last_error' => $message]);
        } catch (Throwable) {
        }
        fwrite(STDERR, "{$environment} StoreKit reconciliation failed: {$message}\n");
    } finally {
        $release = $database->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

exit($failures === 0 ? 0 : 1);
