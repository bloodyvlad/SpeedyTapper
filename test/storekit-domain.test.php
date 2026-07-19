<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\AppleJwsVerifier;
use SpeedyTapper\CoinWalletRepository;
use SpeedyTapper\Config;
use SpeedyTapper\StoreKitAccountRepository;
use SpeedyTapper\StoreKitProductCatalog;
use SpeedyTapper\StoreKitService;
use SpeedyTapper\StoreKitTransaction;

require dirname(__DIR__) . '/server/autoload.php';

/**
 * StoreKit services deliberately use MySQL locking and upsert syntax. This
 * deterministic fixture translates only those dialect fragments so the real
 * repositories and service can be exercised in an in-memory transaction.
 */
final class StoreKitSqlitePdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->exec('PRAGMA foreign_keys = ON');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = preg_replace('/\s+FOR UPDATE\b/i', '', $query) ?? $query;
        $query = preg_replace('/\bUTC_TIMESTAMP\(3\)/i', 'CURRENT_TIMESTAMP', $query) ?? $query;
        $query = preg_replace('/\bGREATEST\(/i', 'MAX(', $query) ?? $query;
        $query = preg_replace('/\bINSERT IGNORE INTO\b/i', 'INSERT OR IGNORE INTO', $query) ?? $query;

        if (str_contains($query, 'ON DUPLICATE KEY UPDATE observation_id = observation_id')) {
            $query = str_replace(
                'ON DUPLICATE KEY UPDATE observation_id = observation_id',
                'ON CONFLICT(transaction_id, payload_hash) DO NOTHING',
                $query,
            );
        } elseif (str_contains($query, 'ON DUPLICATE KEY UPDATE active = VALUES(active)')) {
            $query = preg_replace(
                '/ON DUPLICATE KEY UPDATE active = VALUES\(active\),\s*revoked_at = VALUES\(revoked_at\),\s*player_id = COALESCE\(player_id, VALUES\(player_id\)\)/',
                'ON CONFLICT(player_id, source_transaction_id, capability) DO UPDATE SET '
                    . 'active = excluded.active, revoked_at = excluded.revoked_at, '
                    . 'player_id = COALESCE(player_id, excluded.player_id)',
                $query,
            ) ?? $query;
        } elseif (str_contains($query, 'ON DUPLICATE KEY UPDATE purchase_event_id = VALUES(purchase_event_id)')) {
            if (str_contains($query, 'INSERT INTO player_pets')) {
                $query = str_replace(
                    'ON DUPLICATE KEY UPDATE purchase_event_id = VALUES(purchase_event_id)',
                    'ON CONFLICT(player_id, pet_id) DO UPDATE SET purchase_event_id = excluded.purchase_event_id',
                    $query,
                );
            } else {
                $query = str_replace(
                    'ON DUPLICATE KEY UPDATE purchase_event_id = VALUES(purchase_event_id)',
                    'ON CONFLICT(player_id, theme_id) DO UPDATE SET purchase_event_id = excluded.purchase_event_id',
                    $query,
                );
            }
        }

        return parent::prepare($query, $options);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$rejectsApi = static function (
    callable $operation,
    int $expectedStatus,
    string $message,
) use ($assert): void {
    try {
        $operation();
    } catch (ApiException $error) {
        $assert($error->status === $expectedStatus, $message . ' Unexpected API status.');
        return;
    }
    $assert(false, $message);
};

$products = [
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
];
$catalog = new StoreKitProductCatalog($products);
$assert(
    array_column($catalog->publicCatalog(), 'id') === array_keys($products),
    'The public StoreKit catalog contains the exact five approved product IDs.',
);
$assert(
    $catalog->require('com.otcsoftware.pimpopom.coins.1000.v1')['coins'] === 1000,
    'The signed product ID, not a client quantity, selects the coin amount.',
);
$exampleConfiguration = require dirname(__DIR__) . '/server/config.local.example.php';
$assert(
    json_decode(
        (string) ($exampleConfiguration['SPEEDYTAPPER_STOREKIT_PRODUCTS_JSON'] ?? ''),
        true,
        16,
        JSON_THROW_ON_ERROR,
    ) === $products,
    'The deployable configuration example cannot drift from the exact server product catalog.',
);
$dualEnvironmentMigration = (string) file_get_contents(
    dirname(__DIR__) . '/server/migrations/017_storekit_dual_environment.sql',
);
$assert(
    str_contains($dualEnvironmentMigration, 'apple_transaction_id')
    && str_contains($dualEnvironmentMigration, 'apple_notification_uuid')
    && str_contains($dualEnvironmentMigration, "CONCAT(environment, ':', transaction_id)")
    && str_contains($dualEnvironmentMigration, 'PRIMARY KEY (environment, app_transaction_pseudonym)')
    && str_contains($dualEnvironmentMigration, 'information_schema.TABLE_CONSTRAINTS')
    && str_contains($dualEnvironmentMigration, 'information_schema.COLUMNS')
    && str_contains($dualEnvironmentMigration, 'information_schema.STATISTICS')
    && str_contains($dualEnvironmentMigration, 'DROP TEMPORARY TABLE IF EXISTS')
    && str_contains($dualEnvironmentMigration, 'WHERE apple_transaction_id IS NULL')
    && str_contains($dualEnvironmentMigration, 'WHERE apple_notification_uuid IS NULL'),
    'The forward migration scopes transactions, notifications, and Family Sharing by environment.',
);
$rejectsApi(
    static fn () => new StoreKitProductCatalog(array_slice($products, 0, 4, true)),
    503,
    'A partial StoreKit product allowlist is rejected.',
);
$rejectsApi(
    static fn () => new StoreKitProductCatalog([
        ...$products,
        'com.otcsoftware.pimpopom.coins.9999.test' => [
            'type' => 'consumable', 'coins' => 9999, 'capability' => 'ad_free',
        ],
    ]),
    503,
    'An extra StoreKit product is rejected even when its shape is valid.',
);

$retentionKey = str_repeat('storekit-domain-retention-key-', 2);
$config = new Config(
    databaseHost: 'localhost',
    databasePort: 3306,
    databaseName: 'test',
    databaseUser: 'test',
    databasePassword: 'test',
    googleClientId: 'test.apps.googleusercontent.com',
    seasonId: 'season-1',
    seasonName: 'Season 1',
    storeKitEnvironment: 'Sandbox',
    storeKitAppAppleId: '6792328590',
    storeKitEnvironments: ['Sandbox', 'Production'],
    storeKitProducts: $products,
    storeKitRetentionHmacKey: $retentionKey,
    storeKitRootCertificatePaths: [__DIR__ . '/fixtures/apple-jws/root.pem'],
);
$token = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$assert(
    $config->acceptedStoreKitEnvironments() === ['Sandbox', 'Production']
    && $config->storeKitIsConfigured(),
    'StoreKit can be configured for Sandbox and Production concurrently.',
);
$basePayload = [
    'transactionId' => '2000000000000050',
    'originalTransactionId' => '2000000000000050',
    'productId' => 'com.otcsoftware.pimpopom.coins.50.v1',
    'type' => 'Consumable',
    'environment' => 'Sandbox',
    'bundleId' => 'com.otcsoftware.pimpopom',
    'appAccountToken' => $token,
    'inAppOwnershipType' => 'PURCHASED',
    'quantity' => 1,
    'purchaseDate' => 1_000,
    'signedDate' => 1_100,
];
$purchase = StoreKitTransaction::fromVerifiedPayload($basePayload, $config, $catalog, $token);
$assert($purchase->grossCoins() === 50, 'The verified 50-coin product credits exactly 50 coins.');
$rejectsApi(
    static fn () => StoreKitTransaction::fromVerifiedPayload(
        [
            ...$basePayload,
            'transactionId' => 'family-without-app-transaction',
            'originalTransactionId' => 'family-without-app-transaction',
            'productId' => 'com.otcsoftware.pimpopom.removeads.lifetime',
            'type' => 'Non-Consumable',
            'inAppOwnershipType' => 'FAMILY_SHARED',
            'appAccountToken' => null,
        ],
        $config,
        $catalog,
        $token,
    ),
    400,
    'A Family Shared entitlement without signed appTransactionId is rejected.',
);
$family = StoreKitTransaction::fromVerifiedPayload(
    [
        ...$basePayload,
        'transactionId' => 'family-remove-ads',
        'originalTransactionId' => 'family-remove-ads',
        'appTransactionId' => 'family-member-app-transaction-1',
        'productId' => 'com.otcsoftware.pimpopom.removeads.lifetime',
        'type' => 'Non-Consumable',
        'inAppOwnershipType' => 'FAMILY_SHARED',
        'appAccountToken' => null,
    ],
    $config,
    $catalog,
    $token,
);
$assert(
    $family->ownershipType === 'FAMILY_SHARED' && $family->grossCoins() === 0,
    'A signed Family Shared Remove Ads entitlement grants no coins.',
);
$familyWithPurchaserToken = StoreKitTransaction::fromVerifiedPayload(
    [
        ...$basePayload,
        'transactionId' => 'family-purchaser-token',
        'originalTransactionId' => 'family-purchaser-token',
        'appTransactionId' => 'family-member-app-transaction-purchaser-token',
        'productId' => 'com.otcsoftware.pimpopom.removeads.lifetime',
        'type' => 'Non-Consumable',
        'inAppOwnershipType' => 'FAMILY_SHARED',
        'appAccountToken' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    ],
    $config,
    $catalog,
    $token,
);
$assert(
    $familyWithPurchaserToken->ownershipType === 'FAMILY_SHARED',
    'Family Sharing binds the beneficiary by appTransactionId rather than the purchaser appAccountToken.',
);
$rejectsApi(
    static fn () => StoreKitTransaction::fromVerifiedPayload(
        [...$basePayload, 'inAppOwnershipType' => 'FAMILY_SHARED', 'appTransactionId' => 'family-coins'],
        $config,
        $catalog,
        $token,
    ),
    400,
    'Consumable coin packs cannot be Family Shared.',
);

$database = new StoreKitSqlitePdo();
$database->exec(<<<'SQL'
CREATE TABLE players (
    id TEXT PRIMARY KEY,
    nickname TEXT NOT NULL,
    coins INTEGER NOT NULL DEFAULT 0,
    earned_coins INTEGER NOT NULL DEFAULT 0,
    purchased_coins INTEGER NOT NULL DEFAULT 0,
    coin_debt INTEGER NOT NULL DEFAULT 0,
    earned_coin_debt INTEGER NOT NULL DEFAULT 0,
    refund_coin_debt INTEGER NOT NULL DEFAULT 0,
    total_play_ms INTEGER NOT NULL DEFAULT 0,
    total_coins_collected INTEGER NOT NULL DEFAULT 0,
    coin_time_remainder_ms INTEGER NOT NULL DEFAULT 0,
    economy_generation INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE player_storekit_bindings (
    player_id TEXT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE,
    app_account_token TEXT NOT NULL UNIQUE
);
CREATE TABLE player_storekit_family_bindings (
    environment TEXT NOT NULL,
    app_transaction_pseudonym BLOB NOT NULL,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    account_deleted_at TEXT NULL,
    PRIMARY KEY (environment, app_transaction_pseudonym)
);
CREATE TABLE storekit_transactions (
    transaction_id TEXT PRIMARY KEY,
    apple_transaction_id TEXT NOT NULL,
    original_transaction_id TEXT NOT NULL,
    app_transaction_id TEXT NULL,
    app_transaction_pseudonym BLOB NULL,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    account_token_pseudonym BLOB NOT NULL,
    product_id TEXT NOT NULL,
    product_type TEXT NOT NULL,
    ownership_type TEXT NOT NULL,
    environment TEXT NOT NULL,
    bundle_id TEXT NOT NULL,
    app_apple_id INTEGER NULL,
    signed_quantity INTEGER NOT NULL,
    purchase_date_ms INTEGER NOT NULL,
    signed_date_ms INTEGER NOT NULL,
    lifecycle_signed_date_ms INTEGER NOT NULL,
    revocation_date_ms INTEGER NULL,
    revocation_reason INTEGER NULL,
    status TEXT NOT NULL,
    credited_coins INTEGER NOT NULL DEFAULT 0,
    refund_debt_created INTEGER NOT NULL DEFAULT 0,
    refund_debt_outstanding INTEGER NOT NULL DEFAULT 0,
    base_refund_debt_outstanding INTEGER NOT NULL DEFAULT 0,
    transition_version INTEGER NOT NULL DEFAULT 0,
    account_deleted_at TEXT NULL,
    payload_hash BLOB NOT NULL,
    verified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE purchased_coin_lots (
    transaction_id TEXT PRIMARY KEY REFERENCES storekit_transactions(transaction_id),
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    gross_coins INTEGER NOT NULL,
    available_coins INTEGER NOT NULL,
    spent_coins INTEGER NOT NULL DEFAULT 0,
    refund_debt_settled_coins INTEGER NOT NULL DEFAULT 0,
    reversed_coins INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    credited_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE storekit_transaction_observations (
    observation_id TEXT PRIMARY KEY,
    transaction_id TEXT NOT NULL REFERENCES storekit_transactions(transaction_id),
    observed_state TEXT NOT NULL,
    signed_date_ms INTEGER NOT NULL,
    revocation_date_ms INTEGER NULL,
    payload_hash BLOB NOT NULL,
    observed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (transaction_id, payload_hash)
);
CREATE TABLE player_entitlement_sources (
    source_id TEXT PRIMARY KEY,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    capability TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_transaction_id TEXT NOT NULL REFERENCES storekit_transactions(transaction_id),
    active INTEGER NOT NULL,
    granted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TEXT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (player_id, source_transaction_id, capability)
);
CREATE TABLE coin_ledger (
    event_id TEXT PRIMARY KEY,
    event_key TEXT NOT NULL UNIQUE,
    player_id TEXT NOT NULL REFERENCES players(id),
    economy_generation INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    play_ms_delta INTEGER NOT NULL,
    coin_delta INTEGER NOT NULL,
    earned_delta INTEGER NOT NULL,
    purchased_delta INTEGER NOT NULL,
    coin_balance_after INTEGER NULL,
    earned_balance_after INTEGER NULL,
    purchased_balance_after INTEGER NULL,
    coin_debt_after INTEGER NULL,
    earned_debt_after INTEGER NULL,
    refund_debt_after INTEGER NULL,
    total_play_ms_after INTEGER NOT NULL,
    coin_status TEXT NOT NULL,
    actor TEXT NULL,
    reason TEXT NULL
);
CREATE TABLE coin_spend_allocations (
    allocation_id TEXT PRIMARY KEY,
    spend_event_id TEXT NULL REFERENCES coin_ledger(event_id) ON DELETE SET NULL,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    source TEXT NOT NULL,
    lot_transaction_id TEXT NULL REFERENCES purchased_coin_lots(transaction_id),
    amount INTEGER NOT NULL,
    purpose TEXT NOT NULL,
    spend_reference_pseudonym BLOB NOT NULL,
    released_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE storekit_refund_debt_allocations (
    allocation_id TEXT PRIMARY KEY,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    source_type TEXT NOT NULL,
    source_reference TEXT NOT NULL,
    source_economy_generation INTEGER NULL,
    source_purchase_transaction_id TEXT NULL REFERENCES storekit_transactions(transaction_id),
    refund_transaction_id TEXT NOT NULL REFERENCES storekit_transactions(transaction_id),
    cosmetic_restore_debt_id TEXT NULL,
    amount INTEGER NOT NULL,
    released_amount INTEGER NOT NULL DEFAULT 0,
    source_revoked_at TEXT NULL,
    released_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE player_pets (
    player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    pet_id TEXT NOT NULL,
    price_paid INTEGER NOT NULL,
    acquisition_source TEXT NOT NULL,
    purchase_event_id TEXT NULL,
    PRIMARY KEY (player_id, pet_id)
);
CREATE TABLE player_pet_selection (
    player_id TEXT NOT NULL,
    pet_id TEXT NOT NULL,
    PRIMARY KEY (player_id),
    FOREIGN KEY (player_id, pet_id) REFERENCES player_pets(player_id, pet_id) ON DELETE CASCADE
);
CREATE TABLE player_themes (
    player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    theme_id TEXT NOT NULL,
    price_paid INTEGER NOT NULL,
    purchase_event_id TEXT NULL,
    PRIMARY KEY (player_id, theme_id)
);
CREATE TABLE player_theme_selection (
    player_id TEXT PRIMARY KEY,
    theme_id TEXT NOT NULL
);
CREATE TABLE storekit_refund_cosmetics (
    revocation_id TEXT PRIMARY KEY,
    refund_transaction_id TEXT NOT NULL REFERENCES storekit_transactions(transaction_id),
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    purchase_event_id TEXT NOT NULL,
    refund_cycle INTEGER NOT NULL,
    item_type TEXT NOT NULL,
    item_id TEXT NOT NULL,
    price_paid INTEGER NOT NULL,
    restored_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (refund_transaction_id, refund_cycle, purchase_event_id, item_type, item_id)
);
CREATE TABLE storekit_cosmetic_restore_debts (
    debt_id TEXT PRIMARY KEY,
    refund_transaction_id TEXT NOT NULL REFERENCES storekit_transactions(transaction_id),
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    purchase_event_id TEXT NOT NULL,
    item_type TEXT NOT NULL,
    item_id TEXT NOT NULL,
    amount INTEGER NOT NULL,
    settled_amount INTEGER NOT NULL DEFAULT 0,
    released_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (refund_transaction_id, purchase_event_id)
);
SQL);

$playerId = '11111111-1111-4111-8111-111111111111';
$database->prepare(
    'INSERT INTO players (id, nickname) VALUES (:player_id, :nickname)'
)->execute(['player_id' => $playerId, 'nickname' => 'Ledger tester']);
$database->prepare(
    'INSERT INTO player_storekit_bindings (player_id, app_account_token) VALUES (:player_id, :token)'
)->execute(['player_id' => $playerId, 'token' => $token]);

$accounts = new StoreKitAccountRepository($database, $retentionKey);
$wallets = new CoinWalletRepository($database);
$database->beginTransaction();
$accounts->bindFamilyBeneficiary('Sandbox', 'family-member-app-transaction-1', $playerId);
$database->commit();
$familyBinding = $database->query(
    'SELECT app_transaction_pseudonym, player_id, account_deleted_at '
    . 'FROM player_storekit_family_bindings'
)->fetch();
$assert(
    is_array($familyBinding)
    && $familyBinding['player_id'] === $playerId
    && $familyBinding['account_deleted_at'] === null
    && hash_equals(
        $accounts->familyPseudonym('family-member-app-transaction-1'),
        $familyBinding['app_transaction_pseudonym'],
    ),
    'A Family Shared appTransactionId binds through a keyed pseudonym, never raw Apple identity.',
);
$otherPlayerId = '22222222-2222-4222-8222-222222222222';
$database->prepare(
    'INSERT INTO players (id, nickname) VALUES (:player_id, :nickname)'
)->execute(['player_id' => $otherPlayerId, 'nickname' => 'Another profile']);
$familyTransferRejected = false;
$database->beginTransaction();
try {
    $accounts->bindFamilyBeneficiary('Sandbox', 'family-member-app-transaction-1', $otherPlayerId);
    $database->commit();
} catch (ApiException $error) {
    if ($database->inTransaction()) $database->rollBack();
    $familyTransferRejected = $error->status === 409;
}
$assert($familyTransferRejected, 'A Family Sharing beneficiary cannot be transferred between profiles.');
$service = new StoreKitService(
    $database,
    $config,
    $catalog,
    AppleJwsVerifier::fromPemFiles([__DIR__ . '/fixtures/apple-jws/root.pem']),
    $accounts,
    $wallets,
);
$invoke = static function (object $object, string $method, mixed ...$arguments): mixed {
    $reflection = new ReflectionMethod($object, $method);
    return $reflection->invoke($object, ...$arguments);
};
$scalar = static function (PDO $database, string $sql, array $parameters = []): mixed {
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
};

$detachedFamily = new StoreKitTransaction(
    transactionId: 'family-notification-first',
    originalTransactionId: 'family-notification-first',
    appTransactionId: 'family-member-app-transaction-2',
    productId: 'com.otcsoftware.pimpopom.removeads.lifetime',
    productType: 'non_consumable',
    environment: 'Sandbox',
    bundleId: 'com.otcsoftware.pimpopom',
    appAccountToken: null,
    ownershipType: 'FAMILY_SHARED',
    quantity: 1,
    purchaseDateMs: 1_000,
    signedDateMs: 1_100,
    revocationDateMs: null,
    revocationReason: null,
    catalogProduct: $products['com.otcsoftware.pimpopom.removeads.lifetime'],
);
$detached = $invoke(
    $service,
    'record',
    $detachedFamily,
    'family-notification-first-1100',
    null,
    false,
);
$assert(
    $detached['status'] === 'recorded'
    && $scalar(
        $database,
        "SELECT player_id FROM storekit_transactions WHERE transaction_id = 'Sandbox:family-notification-first'",
    ) === null,
    'A notification-first Family Shared entitlement is retained without guessing a beneficiary.',
);
$attached = $invoke(
    $service,
    'record',
    $detachedFamily,
    'family-notification-first-1100',
    $playerId,
    true,
);
$assert(
    $attached['status'] === 'active'
    && $attached['duplicate'] === false
    && $scalar(
        $database,
        "SELECT player_id FROM storekit_transactions WHERE transaction_id = 'Sandbox:family-notification-first'",
    ) === $playerId
    && (int) $scalar(
        $database,
        "SELECT active FROM player_entitlement_sources WHERE source_transaction_id = 'Sandbox:family-notification-first'",
    ) === 1,
    'A later authenticated restore attaches the Family Shared entitlement to one stable profile.',
);
$rejectsApi(
    static fn () => $invoke(
        $service,
        'record',
        $detachedFamily,
        'family-notification-first-1100',
        $otherPlayerId,
        true,
    ),
    409,
    'A Family Shared entitlement cannot be replayed onto another PimPoPom profile.',
);

$lateBindingToken = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
$lateBindingPurchase = new StoreKitTransaction(
    transactionId: 'late-binding-purchase',
    originalTransactionId: 'late-binding-purchase',
    appTransactionId: null,
    productId: 'com.otcsoftware.pimpopom.coins.50.v1',
    productType: 'consumable',
    environment: 'Sandbox',
    bundleId: 'com.otcsoftware.pimpopom',
    appAccountToken: $lateBindingToken,
    ownershipType: 'PURCHASED',
    quantity: 1,
    purchaseDateMs: 1_000,
    signedDateMs: 1_200,
    revocationDateMs: null,
    revocationReason: null,
    catalogProduct: $products['com.otcsoftware.pimpopom.coins.50.v1'],
);
$invoke($service, 'record', $lateBindingPurchase, 'late-binding-jws', null, false);
$lateBindingPlayerId = '55555555-5555-4555-8555-555555555555';
$database->prepare(
    'INSERT INTO players (id, nickname) VALUES (:player_id, :nickname)'
)->execute(['player_id' => $lateBindingPlayerId, 'nickname' => 'Late binding tester']);
$database->prepare(
    'INSERT INTO player_storekit_bindings (player_id, app_account_token) VALUES (:player_id, :token)'
)->execute(['player_id' => $lateBindingPlayerId, 'token' => $lateBindingToken]);
$lateAttached = $invoke(
    $service,
    'record',
    $lateBindingPurchase,
    'late-binding-jws',
    $lateBindingPlayerId,
    true,
);
$assert(
    $lateAttached['duplicate'] === false
    && $lateAttached['wallet']['purchased'] === 50
    && (int) $scalar(
        $database,
        "SELECT COUNT(*) FROM purchased_coin_lots WHERE transaction_id = 'Sandbox:late-binding-purchase'",
    ) === 1,
    'Detached verified purchase evidence credits once when its signed account token is later bound.',
);

$recorded = $invoke($service, 'record', $purchase, 'fixture-active-1100', $playerId, true);
$assert($recorded['status'] === 'active' && $recorded['duplicate'] === false, 'A first verified purchase is active.');
$assert($recorded['wallet']['purchased'] === 50 && $recorded['wallet']['earned'] === 0, 'A coin pack enters only the purchased wallet.');
$assert((int) $scalar($database, "SELECT available_coins FROM purchased_coin_lots WHERE transaction_id = 'Sandbox:2000000000000050'") === 50, 'The purchase creates a FIFO lot with all 50 coins available.');
$assert((int) $scalar(
    $database,
    "SELECT active FROM player_entitlement_sources WHERE source_transaction_id = 'Sandbox:2000000000000050'",
) === 1, 'A coin pack contributes an account-bound ad-free entitlement source.');
$duplicate = $invoke($service, 'record', $purchase, 'fixture-active-1100', $playerId, true);
$assert($duplicate['duplicate'] === true && $duplicate['wallet']['purchased'] === 50, 'Replaying a transaction ID is idempotent and cannot mint coins twice.');

$database->beginTransaction();
$locked = $wallets->lock($playerId);
$wallets->creditEarned($playerId, 20, $locked);
$database->commit();
$database->beginTransaction();
$mixedSpend = $wallets->spend(
    $playerId,
    30,
    'pet_purchase',
    'pet:foka:test-purchase',
    'pet_purchase',
    'player',
    'Mixed earned and paid cosmetic fixture.',
);
$database->prepare(
    "INSERT INTO player_pets (player_id, pet_id, price_paid, acquisition_source, purchase_event_id) "
    . "VALUES (:player_id, 'foka', 30, 'purchase', :event_id)"
)->execute(['player_id' => $playerId, 'event_id' => $mixedSpend['eventId']]);
$database->commit();
$assert($mixedSpend['earnedSpent'] === 20 && $mixedSpend['purchasedSpent'] === 10, 'Cosmetic spending consumes earned coins first, then purchased FIFO lots.');
$allocations = $database->query(
    'SELECT source, lot_transaction_id, amount FROM coin_spend_allocations ORDER BY source'
)->fetchAll();
$assert(
    $allocations === [
        ['source' => 'earned', 'lot_transaction_id' => null, 'amount' => 20],
        ['source' => 'purchased', 'lot_transaction_id' => 'Sandbox:2000000000000050', 'amount' => 10],
    ],
    'The cosmetic debit records its exact earned/purchased split and Apple lot.',
);

$refund = new StoreKitTransaction(
    transactionId: $purchase->appleTransactionId,
    originalTransactionId: $purchase->originalTransactionId,
    appTransactionId: $purchase->appTransactionId,
    productId: $purchase->productId,
    productType: $purchase->productType,
    environment: $purchase->environment,
    bundleId: $purchase->bundleId,
    appAccountToken: $purchase->appAccountToken,
    ownershipType: $purchase->ownershipType,
    quantity: $purchase->quantity,
    purchaseDateMs: $purchase->purchaseDateMs,
    signedDateMs: 2_000,
    revocationDateMs: 1_900,
    revocationReason: 1,
    catalogProduct: $purchase->catalogProduct,
);
$refunded = $invoke($service, 'refund', $refund, 'fixture-refund-2000', 'REFUND', $playerId);
$assert($refunded['status'] === 'refunded' && $refunded['duplicate'] === false, 'A newer signed refund changes the transaction state.');
$assert($refunded['wallet'] === [
    'earned' => 20,
    'purchased' => 0,
    'earnedDebt' => 0,
    'refundDebt' => 0,
    'total' => 20,
], 'Refund revokes the paid lot but restores the unrelated earned cosmetic allocation.');
$assert((int) $scalar($database, "SELECT COUNT(*) FROM player_pets WHERE pet_id = 'foka'") === 0, 'A cosmetic funded by the refunded Apple transaction is revoked.');
$refundedLot = $database->query(
    "SELECT available_coins, spent_coins, reversed_coins, status FROM purchased_coin_lots "
    . "WHERE transaction_id = 'Sandbox:2000000000000050'"
)->fetch();
$assert($refundedLot === [
    'available_coins' => 0,
    'spent_coins' => 0,
    'reversed_coins' => 50,
    'status' => 'refunded',
], 'Refund removes all unspent and cosmetic-funded value from the exact purchased lot.');
$assert((int) $scalar(
    $database,
    "SELECT active FROM player_entitlement_sources WHERE source_transaction_id = 'Sandbox:2000000000000050'",
) === 0, 'Refund revokes the coin pack ad-free entitlement source.');

$reversal = new StoreKitTransaction(
    transactionId: $purchase->appleTransactionId,
    originalTransactionId: $purchase->originalTransactionId,
    appTransactionId: $purchase->appTransactionId,
    productId: $purchase->productId,
    productType: $purchase->productType,
    environment: $purchase->environment,
    bundleId: $purchase->bundleId,
    appAccountToken: $purchase->appAccountToken,
    ownershipType: $purchase->ownershipType,
    quantity: $purchase->quantity,
    purchaseDateMs: $purchase->purchaseDateMs,
    signedDateMs: 3_000,
    revocationDateMs: null,
    revocationReason: null,
    catalogProduct: $purchase->catalogProduct,
);
$restored = $invoke($service, 'restore', $reversal, 'fixture-reversal-3000');
$assert($restored['status'] === 'reinstated' && $restored['duplicate'] === false, 'A newer refund reversal reinstates the transaction.');
$assert($restored['wallet'] === [
    'earned' => 0,
    'purchased' => 40,
    'earnedDebt' => 0,
    'refundDebt' => 0,
    'total' => 40,
], 'Refund reversal re-creates the cosmetic debit with the original effective wallet value.');
$assert((int) $scalar($database, "SELECT COUNT(*) FROM player_pets WHERE pet_id = 'foka'") === 1, 'Refund reversal restores the revoked cosmetic.');
$restoredLot = $database->query(
    "SELECT available_coins, spent_coins, reversed_coins, status FROM purchased_coin_lots "
    . "WHERE transaction_id = 'Sandbox:2000000000000050'"
)->fetch();
$assert($restoredLot === [
    'available_coins' => 40,
    'spent_coins' => 10,
    'reversed_coins' => 0,
    'status' => 'reinstated',
], 'Refund reversal restores the paid lot and reapplies only the cosmetic debit.');
$assert((int) $scalar(
    $database,
    "SELECT active FROM player_entitlement_sources WHERE source_transaction_id = 'Sandbox:2000000000000050'",
) === 1, 'Refund reversal restores the ad-free entitlement source.');

$staleRefund = new StoreKitTransaction(
    transactionId: $refund->appleTransactionId,
    originalTransactionId: $refund->originalTransactionId,
    appTransactionId: $refund->appTransactionId,
    productId: $refund->productId,
    productType: $refund->productType,
    environment: $refund->environment,
    bundleId: $refund->bundleId,
    appAccountToken: $refund->appAccountToken,
    ownershipType: $refund->ownershipType,
    quantity: $refund->quantity,
    purchaseDateMs: $refund->purchaseDateMs,
    signedDateMs: 2_500,
    revocationDateMs: 2_400,
    revocationReason: 1,
    catalogProduct: $refund->catalogProduct,
);
$stale = $invoke($service, 'refund', $staleRefund, 'fixture-stale-refund-2500', 'REFUND', $playerId);
$assert($stale['duplicate'] === true && $stale['status'] === 'reinstated', 'An older signed refund cannot roll back a newer reversal.');
$assert((int) $scalar($database, "SELECT signed_date_ms FROM storekit_transactions WHERE transaction_id = 'Sandbox:2000000000000050'") === 3_000, 'A stale transition cannot lower the signed-date watermark.');
$assert($accounts->walletSummary($playerId)['total'] === 40, 'A stale transition does not alter spendable value.');

$outerNewerRefund = $invoke(
    $service,
    'refund',
    $staleRefund,
    'fixture-outer-newer-refund',
    'REFUND',
    $playerId,
    4_000,
);
$assert(
    $outerNewerRefund['status'] === 'refunded'
    && $outerNewerRefund['duplicate'] === false
    && (int) $scalar(
        $database,
        "SELECT lifecycle_signed_date_ms FROM storekit_transactions WHERE transaction_id = 'Sandbox:2000000000000050'",
    ) === 4_000,
    'The outer notification signed date, not the nested transaction date, orders lifecycle snapshots.',
);
$innerNewerOuterStale = new StoreKitTransaction(
    transactionId: $reversal->appleTransactionId,
    originalTransactionId: $reversal->originalTransactionId,
    appTransactionId: $reversal->appTransactionId,
    productId: $reversal->productId,
    productType: $reversal->productType,
    environment: $reversal->environment,
    bundleId: $reversal->bundleId,
    appAccountToken: $reversal->appAccountToken,
    ownershipType: $reversal->ownershipType,
    quantity: $reversal->quantity,
    purchaseDateMs: $reversal->purchaseDateMs,
    signedDateMs: 5_000,
    revocationDateMs: null,
    revocationReason: null,
    catalogProduct: $reversal->catalogProduct,
);
$outerStaleRestore = $invoke(
    $service,
    'restore',
    $innerNewerOuterStale,
    'fixture-outer-stale-reversal',
    $playerId,
    3_500,
);
$assert(
    $outerStaleRestore['status'] === 'refunded'
    && $outerStaleRestore['duplicate'] === true
    && $accounts->walletSummary($playerId)['purchased'] === 0,
    'A newer nested JWS cannot let an older outer notification reverse the current lifecycle state.',
);

$reversalFirstPlayerId = '44444444-4444-4444-8444-444444444444';
$reversalFirstToken = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$database->prepare(
    'INSERT INTO players (id, nickname) VALUES (:player_id, :nickname)'
)->execute(['player_id' => $reversalFirstPlayerId, 'nickname' => 'Reversal-first tester']);
$database->prepare(
    'INSERT INTO player_storekit_bindings (player_id, app_account_token) VALUES (:player_id, :token)'
)->execute(['player_id' => $reversalFirstPlayerId, 'token' => $reversalFirstToken]);
$reversalFirst = new StoreKitTransaction(
    transactionId: 'reversal-first-purchase',
    originalTransactionId: 'reversal-first-purchase',
    appTransactionId: null,
    productId: 'com.otcsoftware.pimpopom.coins.50.v1',
    productType: 'consumable',
    environment: 'Sandbox',
    bundleId: 'com.otcsoftware.pimpopom',
    appAccountToken: $reversalFirstToken,
    ownershipType: 'PURCHASED',
    quantity: 1,
    purchaseDateMs: 1_000,
    signedDateMs: 2_000,
    revocationDateMs: null,
    revocationReason: null,
    catalogProduct: $products['com.otcsoftware.pimpopom.coins.50.v1'],
);
$reversalFirstResult = $invoke(
    $service,
    'restore',
    $reversalFirst,
    'fixture-reversal-first',
    $reversalFirstPlayerId,
    2_100,
);
$reversalFirstReplay = $invoke(
    $service,
    'restore',
    $reversalFirst,
    'fixture-reversal-first',
    $reversalFirstPlayerId,
    2_100,
);
$assert(
    $reversalFirstResult['status'] === 'reinstated'
    && $reversalFirstResult['duplicate'] === false
    && $reversalFirstReplay['duplicate'] === true
    && $accounts->walletSummary($reversalFirstPlayerId)['purchased'] === 50,
    'A refund reversal received before local purchase evidence records and credits exactly once.',
);

// Crossed refund/reversal order: purchase Y first settles purchase X's refund
// debt, both are refunded, then X and Y are restored in that order. Y must
// settle X again rather than surface as spendable coins.
$chainPlayerId = '33333333-3333-4333-8333-333333333333';
$chainToken = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$database->prepare(
    'INSERT INTO players (id, nickname) VALUES (:player_id, :nickname)'
)->execute(['player_id' => $chainPlayerId, 'nickname' => 'Refund chain tester']);
$database->prepare(
    'INSERT INTO player_storekit_bindings (player_id, app_account_token) VALUES (:player_id, :token)'
)->execute(['player_id' => $chainPlayerId, 'token' => $chainToken]);
$makeTransaction = static function (
    string $transactionId,
    string $appAccountToken,
    int $signedDateMs,
    ?int $revocationDateMs = null,
) use ($products): StoreKitTransaction {
    return new StoreKitTransaction(
        transactionId: $transactionId,
        originalTransactionId: $transactionId,
        appTransactionId: null,
        productId: 'com.otcsoftware.pimpopom.coins.50.v1',
        productType: 'consumable',
        environment: 'Sandbox',
        bundleId: 'com.otcsoftware.pimpopom',
        appAccountToken: $appAccountToken,
        ownershipType: 'PURCHASED',
        quantity: 1,
        purchaseDateMs: 1_000,
        signedDateMs: $signedDateMs,
        revocationDateMs: $revocationDateMs,
        revocationReason: $revocationDateMs === null ? null : 1,
        catalogProduct: $products['com.otcsoftware.pimpopom.coins.50.v1'],
    );
};

$database->beginTransaction();
$chainPlayer = $wallets->lock($chainPlayerId);
$wallets->creditEarned($chainPlayerId, 100, $chainPlayer);
$database->commit();
$purchaseX = $makeTransaction('chain-purchase-x', $chainToken, 1_100);
$invoke($service, 'record', $purchaseX, 'chain-x-active-1100', $chainPlayerId, true);
$database->beginTransaction();
$largePetSpend = $wallets->spend(
    $chainPlayerId,
    120,
    'pet_purchase',
    'pet:foka:chain-purchase',
    'pet_purchase',
    'player',
    'Large mixed-provenance cosmetic fixture.',
);
$database->prepare(
    "INSERT INTO player_pets (player_id, pet_id, price_paid, acquisition_source, purchase_event_id) "
    . "VALUES (:player_id, 'foka', 120, 'purchase', :event_id)"
)->execute(['player_id' => $chainPlayerId, 'event_id' => $largePetSpend['eventId']]);
$database->commit();
$assert(
    $largePetSpend['earnedSpent'] === 100 && $largePetSpend['purchasedSpent'] === 20,
    'The chain fixture begins with a large cosmetic funded by earned value and purchase X.',
);

$refundX1 = $makeTransaction('chain-purchase-x', $chainToken, 2_000, 1_900);
$invoke($service, 'refund', $refundX1, 'chain-x-refund-2000', 'REFUND', $chainPlayerId);
$assert(
    $accounts->walletSummary($chainPlayerId)['earned'] === 100,
    'Refunding X restores the unrelated earned portion of its cosmetic.',
);
$database->beginTransaction();
$unrelatedSpend = $wallets->spend(
    $chainPlayerId,
    100,
    'theme_purchase',
    'theme:pixel:chain-purchase',
    'theme_purchase',
    'player',
    'Unrelated cosmetic purchased while X is refunded.',
);
$database->prepare(
    "INSERT INTO player_themes (player_id, theme_id, price_paid, purchase_event_id) "
    . "VALUES (:player_id, 'pixel', 100, :event_id)"
)->execute(['player_id' => $chainPlayerId, 'event_id' => $unrelatedSpend['eventId']]);
$database->commit();

$restoreX1 = $makeTransaction('chain-purchase-x', $chainToken, 3_000);
$invoke($service, 'restore', $restoreX1, 'chain-x-reversal-3000');
$chainWallet = $accounts->walletSummary($chainPlayerId);
$assert(
    $chainWallet['total'] === 0 && $chainWallet['refundDebt'] === 70,
    'Restoring X recreates its cosmetic and records the unavailable 70-coin shortfall.',
);
$chainDebt = $database->query(
    "SELECT amount, settled_amount, released_at FROM storekit_cosmetic_restore_debts "
    . "WHERE refund_transaction_id = 'Sandbox:chain-purchase-x'"
)->fetch();
$assert(
    $chainDebt === ['amount' => 70, 'settled_amount' => 0, 'released_at' => null],
    'The cosmetic shortfall is retained as an open exact debt component.',
);

$purchaseY = $makeTransaction('chain-purchase-y', $chainToken, 3_100);
$invoke($service, 'record', $purchaseY, 'chain-y-active-3100', $chainPlayerId, true);
$assert(
    $accounts->walletSummary($chainPlayerId)['refundDebt'] === 20
    && (int) $scalar(
        $database,
        "SELECT refund_debt_settled_coins FROM purchased_coin_lots WHERE transaction_id = 'Sandbox:chain-purchase-y'",
    ) === 50,
    'Purchase Y settles 50 coins of X debt and retains exact lot provenance.',
);

$refundY = $makeTransaction('chain-purchase-y', $chainToken, 4_000, 3_900);
$invoke($service, 'refund', $refundY, 'chain-y-refund-4000', 'REFUND', $chainPlayerId);
$assert(
    $accounts->walletSummary($chainPlayerId)['refundDebt'] === 70,
    'Refunding debt-settlement purchase Y restores its exact 50-coin shortfall.',
);

$refundX2 = $makeTransaction('chain-purchase-x', $chainToken, 5_000, 4_900);
$invoke($service, 'refund', $refundX2, 'chain-x-refund-5000', 'REFUND', $chainPlayerId);
$chainYLot = $database->query(
    "SELECT refund_debt_settled_coins, reversed_coins, status FROM purchased_coin_lots "
    . "WHERE transaction_id = 'Sandbox:chain-purchase-y'"
)->fetch();
$assert(
    $accounts->walletSummary($chainPlayerId)['refundDebt'] === 0
    && $chainYLot === [
        'refund_debt_settled_coins' => 0,
        'reversed_coins' => 50,
        'status' => 'refunded',
    ],
    'Refunding X unwinds Y through the provenance chain without leaving phantom debt.',
);

$restoreX2 = $makeTransaction('chain-purchase-x', $chainToken, 6_000);
$invoke($service, 'restore', $restoreX2, 'chain-x-reversal-6000');
$assert(
    $accounts->walletSummary($chainPlayerId)['refundDebt'] === 70,
    'Restoring X again deterministically recreates the 70-coin cosmetic shortfall.',
);
$restoreY = $makeTransaction('chain-purchase-y', $chainToken, 7_000);
$invoke($service, 'restore', $restoreY, 'chain-y-reversal-7000');
$chainWallet = $accounts->walletSummary($chainPlayerId);
$chainYLot = $database->query(
    "SELECT available_coins, refund_debt_settled_coins, reversed_coins, status "
    . "FROM purchased_coin_lots WHERE transaction_id = 'Sandbox:chain-purchase-y'"
)->fetch();
$assert(
    $chainWallet['total'] === 0
    && $chainWallet['refundDebt'] === 20
    && $chainYLot === [
        'available_coins' => 0,
        'refund_debt_settled_coins' => 50,
        'reversed_coins' => 0,
        'status' => 'reinstated',
    ],
    'Restoring Y after X repays X debt again instead of exposing refunded value as spendable.',
);
$duplicateRestoreY = $invoke($service, 'restore', $restoreY, 'chain-y-reversal-7000');
$assert(
    $duplicateRestoreY['duplicate'] === true
    && $accounts->walletSummary($chainPlayerId)['refundDebt'] === 20,
    'Replaying the crossed refund reversal is idempotent.',
);

$dualPlayerId = '66666666-6666-4666-8666-666666666666';
$dualToken = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
$database->prepare(
    'INSERT INTO players (id, nickname) VALUES (:player_id, :nickname)'
)->execute(['player_id' => $dualPlayerId, 'nickname' => 'Dual environment tester']);
$database->prepare(
    'INSERT INTO player_storekit_bindings (player_id, app_account_token) VALUES (:player_id, :token)'
)->execute(['player_id' => $dualPlayerId, 'token' => $dualToken]);
$dualBase = [
    ...$basePayload,
    'transactionId' => 'same-id-in-both-environments',
    'originalTransactionId' => 'same-id-in-both-environments',
    'appAccountToken' => $dualToken,
];
$dualSandbox = StoreKitTransaction::fromVerifiedPayload(
    $dualBase,
    $config,
    $catalog,
    $dualToken,
);
$dualProduction = StoreKitTransaction::fromVerifiedPayload(
    [...$dualBase, 'environment' => 'Production'],
    $config,
    $catalog,
    $dualToken,
);
$invoke($service, 'record', $dualSandbox, 'dual-sandbox-jws', $dualPlayerId, true);
$invoke($service, 'record', $dualProduction, 'dual-production-jws', $dualPlayerId, true);
$dualReplay = $invoke(
    $service,
    'record',
    $dualProduction,
    'dual-production-jws',
    $dualPlayerId,
    true,
);
$assert(
    (int) $scalar(
        $database,
        "SELECT COUNT(*) FROM storekit_transactions WHERE apple_transaction_id = 'same-id-in-both-environments'",
    ) === 2
    && $accounts->walletSummary($dualPlayerId)['purchased'] === 100
    && $dualReplay['duplicate'] === true,
    'Sandbox and Production scope the same Apple transaction ID independently and each credit only once.',
);

$database->beginTransaction();
$accounts->bindFamilyBeneficiary('Production', 'family-member-app-transaction-1', $otherPlayerId);
$database->commit();
$assert(
    $accounts->playerIdForFamilyAppTransaction(
        'Sandbox',
        'family-member-app-transaction-1',
    ) === $playerId
    && $accounts->playerIdForFamilyAppTransaction(
        'Production',
        'family-member-app-transaction-1',
    ) === $otherPlayerId,
    'Family Sharing appTransactionId bindings are isolated by signed environment.',
);

fwrite(STDOUT, "StoreKit domain tests passed ({$assertions} assertions).\n");
