<?php

declare(strict_types=1);

use SpeedyTapper\AccountDeletionService;
use SpeedyTapper\ApiException;
use SpeedyTapper\StoreKitPseudonym;

require dirname(__DIR__) . '/server/autoload.php';

final class AccountDeletionSqlitePdo extends PDO
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
$database = new AccountDeletionSqlitePdo();
$database->exec(<<<'SQL'
CREATE TABLE players (id TEXT PRIMARY KEY, nickname TEXT NOT NULL);
CREATE TABLE player_sessions (
    session_auth_id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE
);
CREATE TABLE leaderboard_entries (
    id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    moderated_by TEXT NULL
);
CREATE TABLE completed_runs (
    run_id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    leaderboard_entry_id TEXT NULL REFERENCES leaderboard_entries(id) ON DELETE SET NULL,
    moderated_by TEXT NULL
);
CREATE TABLE run_attempts (
    run_id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL REFERENCES players(id) ON DELETE RESTRICT
);
CREATE TABLE run_trace_claims (
    trace_hash BLOB PRIMARY KEY,
    first_run_id TEXT NOT NULL REFERENCES run_attempts(run_id) ON DELETE RESTRICT
);
CREATE TABLE run_proofs (
    run_id TEXT PRIMARY KEY REFERENCES run_attempts(run_id) ON DELETE CASCADE,
    proof_json TEXT NOT NULL
);
CREATE TABLE leaderboard_moderation_events (
    event_id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL,
    actor TEXT NOT NULL
);
CREATE TABLE coin_ledger (
    event_id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL,
    actor TEXT NULL
);
CREATE TABLE account_reward_resets (
    reset_id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL REFERENCES players(id) ON DELETE RESTRICT,
    actor_player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL
);
CREATE TABLE player_roles (
    player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    role TEXT NOT NULL,
    PRIMARY KEY (player_id, role)
);
CREATE TABLE player_achievements (
    player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    achievement_key TEXT NOT NULL,
    PRIMARY KEY (player_id, achievement_key)
);
CREATE TABLE player_pets (
    player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    pet_id TEXT NOT NULL,
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
    PRIMARY KEY (player_id, theme_id)
);
CREATE TABLE player_theme_selection (
    player_id TEXT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE,
    theme_id TEXT NOT NULL
);
CREATE TABLE player_storekit_bindings (
    player_id TEXT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE,
    app_account_token TEXT NOT NULL UNIQUE
);
CREATE TABLE player_storekit_family_bindings (
    app_transaction_pseudonym BLOB PRIMARY KEY,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    account_deleted_at TEXT NULL
);
CREATE TABLE storekit_transactions (
    transaction_id TEXT PRIMARY KEY,
    app_transaction_id TEXT NULL,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    account_token_pseudonym BLOB NOT NULL,
    account_deleted_at TEXT NULL,
    product_id TEXT NOT NULL,
    bundle_id TEXT NOT NULL,
    environment TEXT NOT NULL,
    status TEXT NOT NULL,
    credited_coins INTEGER NOT NULL,
    payload_hash BLOB NOT NULL
);
CREATE TABLE purchased_coin_lots (
    transaction_id TEXT PRIMARY KEY REFERENCES storekit_transactions(transaction_id) ON DELETE RESTRICT,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    gross_coins INTEGER NOT NULL,
    available_coins INTEGER NOT NULL
);
CREATE TABLE player_entitlement_sources (
    source_id TEXT PRIMARY KEY,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    source_transaction_id TEXT NOT NULL REFERENCES storekit_transactions(transaction_id) ON DELETE RESTRICT,
    capability TEXT NOT NULL,
    active INTEGER NOT NULL
);
CREATE TABLE coin_spend_allocations (
    allocation_id TEXT PRIMARY KEY,
    spend_event_id TEXT NULL REFERENCES coin_ledger(event_id) ON DELETE SET NULL,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    source TEXT NOT NULL,
    lot_transaction_id TEXT NULL REFERENCES purchased_coin_lots(transaction_id) ON DELETE RESTRICT,
    amount INTEGER NOT NULL,
    spend_reference_pseudonym BLOB NOT NULL
);
CREATE TABLE storekit_refund_debt_allocations (
    allocation_id TEXT PRIMARY KEY,
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    source_type TEXT NOT NULL,
    source_reference TEXT NOT NULL,
    source_purchase_transaction_id TEXT NULL REFERENCES storekit_transactions(transaction_id),
    refund_transaction_id TEXT NOT NULL REFERENCES storekit_transactions(transaction_id),
    amount INTEGER NOT NULL
);
CREATE TABLE storekit_refund_cosmetics (
    revocation_id TEXT PRIMARY KEY,
    refund_transaction_id TEXT NOT NULL REFERENCES storekit_transactions(transaction_id),
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    purchase_event_id TEXT NOT NULL,
    item_type TEXT NOT NULL,
    item_id TEXT NOT NULL,
    price_paid INTEGER NOT NULL
);
CREATE TABLE storekit_cosmetic_restore_debts (
    debt_id TEXT PRIMARY KEY,
    refund_transaction_id TEXT NOT NULL REFERENCES storekit_transactions(transaction_id),
    player_id TEXT NULL REFERENCES players(id) ON DELETE SET NULL,
    purchase_event_id TEXT NOT NULL,
    item_type TEXT NOT NULL,
    item_id TEXT NOT NULL,
    amount INTEGER NOT NULL
);
CREATE TABLE storekit_notifications (
    notification_uuid TEXT PRIMARY KEY,
    transaction_id TEXT NULL REFERENCES storekit_transactions(transaction_id) ON DELETE SET NULL,
    notification_type TEXT NOT NULL,
    payload_hash BLOB NOT NULL
);
SQL);

$target = '11111111-1111-4111-8111-111111111111';
$other = '22222222-2222-4222-8222-222222222222';
$failed = '33333333-3333-4333-8333-333333333333';
$bare = '44444444-4444-4444-8444-444444444444';
$token = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$key = str_repeat('retention-key-', 3);
$storedAccountPseudonym = str_repeat("\x11", 32);
$familyPseudonym = str_repeat("\x12", 32);

$insert = static function (PDO $database, string $sql, array $parameters = []): void {
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
};
$count = static function (PDO $database, string $table, ?string $where = null, array $parameters = []): int {
    $statement = $database->prepare(
        'SELECT COUNT(*) FROM ' . $table . ($where === null ? '' : ' WHERE ' . $where)
    );
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
};

foreach (
    [
        [$target, 'Delete me'],
        [$other, 'Keep me'],
        [$failed, 'Rollback me'],
        [$bare, 'No purchase yet'],
    ] as [$id, $nickname]
) {
    $insert($database, 'INSERT INTO players (id, nickname) VALUES (:id, :nickname)', compact('id', 'nickname'));
}
$insert($database, 'INSERT INTO player_sessions VALUES (:session, :player)', ['session' => 'session-target', 'player' => $target]);
$insert($database, 'INSERT INTO leaderboard_entries VALUES (:id, :player, NULL)', ['id' => 'entry-target', 'player' => $target]);
$insert($database, 'INSERT INTO completed_runs VALUES (:run, :player, :entry, NULL)', ['run' => 'run-target', 'player' => $target, 'entry' => 'entry-target']);
$insert($database, 'INSERT INTO run_attempts VALUES (:run, :player)', ['run' => 'attempt-target', 'player' => $target]);
$insert($database, 'INSERT INTO run_trace_claims VALUES (:hash, :run)', ['hash' => random_bytes(32), 'run' => 'attempt-target']);
$insert($database, 'INSERT INTO run_proofs VALUES (:run, :proof)', ['run' => 'attempt-target', 'proof' => '{"events":[]}']);
$insert($database, 'INSERT INTO leaderboard_moderation_events VALUES (:id, :player, :actor)', ['id' => 'moderation-target', 'player' => $target, 'actor' => 'admin:' . $target]);
$insert($database, 'INSERT INTO coin_ledger VALUES (:id, :player, NULL)', ['id' => 'ledger-earned', 'player' => $target]);
$insert($database, 'INSERT INTO coin_ledger VALUES (:id, :player, NULL)', ['id' => 'ledger-purchased', 'player' => $target]);
$insert($database, 'INSERT INTO leaderboard_entries VALUES (:id, :player, :actor)', ['id' => 'entry-other', 'player' => $other, 'actor' => 'admin:' . $target]);
$insert($database, 'INSERT INTO completed_runs VALUES (:run, :player, :entry, :actor)', ['run' => 'run-other', 'player' => $other, 'entry' => 'entry-other', 'actor' => 'admin:' . $target]);
$insert($database, 'INSERT INTO leaderboard_moderation_events VALUES (:id, :player, :actor)', ['id' => 'moderation-other', 'player' => $other, 'actor' => 'admin:' . $target]);
$insert($database, 'INSERT INTO coin_ledger VALUES (:id, :player, :actor)', ['id' => 'ledger-other', 'player' => $other, 'actor' => 'admin:' . $target]);
$insert($database, 'INSERT INTO account_reward_resets VALUES (:id, :player, :actor)', ['id' => 'reset-target', 'player' => $target, 'actor' => $other]);
$insert($database, 'INSERT INTO account_reward_resets VALUES (:id, :player, :actor)', ['id' => 'reset-actor', 'player' => $other, 'actor' => $target]);
$insert($database, 'INSERT INTO player_roles VALUES (:player, :role)', ['player' => $target, 'role' => 'leaderboard_admin']);
$insert($database, 'INSERT INTO player_achievements VALUES (:player, :key)', ['player' => $target, 'key' => 'complete_arcade']);
$insert($database, 'INSERT INTO player_pets VALUES (:player, :pet)', ['player' => $target, 'pet' => 'foka']);
$insert($database, 'INSERT INTO player_pet_selection VALUES (:player, :pet)', ['player' => $target, 'pet' => 'foka']);
$insert($database, 'INSERT INTO player_themes VALUES (:player, :theme)', ['player' => $target, 'theme' => 'pixel']);
$insert($database, 'INSERT INTO player_theme_selection VALUES (:player, :theme)', ['player' => $target, 'theme' => 'pixel']);
$insert($database, 'INSERT INTO player_storekit_bindings VALUES (:player, :token)', ['player' => $target, 'token' => $token]);
$familyInsert = $database->prepare(
    'INSERT INTO player_storekit_family_bindings '
    . '(app_transaction_pseudonym, player_id) VALUES (:pseudonym, :player)'
);
$familyInsert->bindValue(':pseudonym', $familyPseudonym, PDO::PARAM_LOB);
$familyInsert->bindValue(':player', $target);
$familyInsert->execute();
$transactionInsert = $database->prepare(
    'INSERT INTO storekit_transactions '
    . '(transaction_id, player_id, account_token_pseudonym, product_id, bundle_id, environment, '
    . 'status, credited_coins, payload_hash) VALUES '
    . '(:transaction, :player, :pseudonym, :product, :bundle, :environment, :status, :coins, :hash)'
);
$transactionInsert->bindValue(':transaction', 'apple-transaction-target');
$transactionInsert->bindValue(':player', $target);
$transactionInsert->bindValue(':pseudonym', $storedAccountPseudonym, PDO::PARAM_LOB);
$transactionInsert->bindValue(':product', 'com.otcsoftware.pimpopom.coins.100.v1');
$transactionInsert->bindValue(':bundle', 'com.otcsoftware.pimpopom');
$transactionInsert->bindValue(':environment', 'Sandbox');
$transactionInsert->bindValue(':status', 'refunded');
$transactionInsert->bindValue(':coins', 100, PDO::PARAM_INT);
$transactionInsert->bindValue(':hash', random_bytes(32), PDO::PARAM_LOB);
$transactionInsert->execute();
$insert($database, 'INSERT INTO purchased_coin_lots VALUES (:transaction, :player, 100, 40)', ['transaction' => 'apple-transaction-target', 'player' => $target]);
$insert($database, 'INSERT INTO player_entitlement_sources VALUES (:id, :player, :transaction, :capability, 1)', ['id' => 'entitlement-target', 'player' => $target, 'transaction' => 'apple-transaction-target', 'capability' => 'ad_free']);
$insert($database, 'INSERT INTO coin_spend_allocations VALUES (:id, :event, :player, :source, :lot, 60, :pseudonym)', ['id' => 'allocation-purchased', 'event' => 'ledger-purchased', 'player' => $target, 'source' => 'purchased', 'lot' => 'apple-transaction-target', 'pseudonym' => str_repeat("\x22", 32)]);
$insert($database, 'INSERT INTO coin_spend_allocations VALUES (:id, :event, :player, :source, NULL, 5, :pseudonym)', ['id' => 'allocation-earned', 'event' => 'ledger-earned', 'player' => $target, 'source' => 'earned', 'pseudonym' => str_repeat("\x33", 32)]);
$insert($database, "INSERT INTO storekit_refund_debt_allocations VALUES ('refund-earned', :player, 'earned_credit', 'earned-run-reference', NULL, 'apple-transaction-target', 4)", ['player' => $target]);
$insert($database, "INSERT INTO storekit_refund_debt_allocations VALUES ('refund-purchased', :player, 'storekit_purchase', 'apple-source-reference', 'apple-transaction-target', 'apple-transaction-target', 3)", ['player' => $target]);
$insert($database, "INSERT INTO storekit_refund_cosmetics VALUES ('refund-cosmetic', 'apple-transaction-target', :player, 'ledger-purchased', 'pet', 'foka', 60)", ['player' => $target]);
$insert($database, "INSERT INTO storekit_cosmetic_restore_debts VALUES ('restore-debt', 'apple-transaction-target', :player, 'ledger-purchased', 'pet', 'foka', 2)", ['player' => $target]);
$insert($database, 'INSERT INTO storekit_notifications VALUES (:id, :transaction, :type, :hash)', ['id' => 'notification-target', 'transaction' => 'apple-transaction-target', 'type' => 'REFUND', 'hash' => random_bytes(32)]);

$service = new AccountDeletionService($database, $key);
$result = $service->delete($target);
$assert($result === [
    'deleted' => true,
    'retainedStoreKitTransactions' => 1,
    'retainedPurchasedCoinLots' => 1,
    'retainedEntitlementSources' => 1,
    'retainedPurchasedSpendAllocations' => 1,
    'retainedRefundDebtSettlements' => 2,
    'retainedRefundedCosmetics' => 1,
    'retainedCosmeticRestoreDebts' => 1,
], 'Deletion reports every retained paid-value evidence class.');
$assert($count($database, 'players', 'id = :id', ['id' => $target]) === 0, 'The identity and nickname row are removed.');
$assert($count($database, 'players', 'id = :id', ['id' => $other]) === 1, 'An unrelated identity remains.');
foreach (
    [
        'player_sessions',
        'run_attempts',
        'run_trace_claims',
        'run_proofs',
        'player_roles',
        'player_achievements',
        'player_pets',
        'player_pet_selection',
        'player_themes',
        'player_theme_selection',
        'player_storekit_bindings',
    ] as $table
) {
    $assert($count($database, $table) === 0, $table . ' no longer retains deleted-account data.');
}
$assert(
    $count($database, 'account_reward_resets') === 1
    && $database->query(
        "SELECT actor_player_id FROM account_reward_resets WHERE reset_id = 'reset-actor'"
    )->fetchColumn() === null,
    'Another player\'s reward-reset audit survives with the deleted administrator detached.',
);
$assert($count($database, 'leaderboard_entries') === 1, 'Only the unrelated public result remains.');
$assert($count($database, 'completed_runs') === 1, 'Only the unrelated completed run remains.');
$assert($count($database, 'leaderboard_moderation_events') === 1, 'Only unrelated moderation history remains.');
$assert($count($database, 'coin_ledger') === 1, 'Only the unrelated economy ledger remains.');
$assert(
    $database->query("SELECT moderated_by FROM leaderboard_entries WHERE id = 'entry-other'")->fetchColumn()
        === 'deleted-account'
    && $database->query("SELECT moderated_by FROM completed_runs WHERE run_id = 'run-other'")->fetchColumn()
        === 'deleted-account'
    && $database->query("SELECT actor FROM leaderboard_moderation_events WHERE event_id = 'moderation-other'")->fetchColumn()
        === 'deleted-account'
    && $database->query("SELECT actor FROM coin_ledger WHERE event_id = 'ledger-other'")->fetchColumn()
        === null,
    'Administrator actor references on other accounts are anonymized without deleting their records.',
);

$transaction = $database->query(
    "SELECT * FROM storekit_transactions WHERE transaction_id = 'apple-transaction-target'"
)->fetch();
$expectedDeletionPseudonym = StoreKitPseudonym::account($key, $token);
$assert(
    is_array($transaction)
    && $transaction['player_id'] === null
    && $transaction['account_deleted_at'] !== null,
    'Apple transaction evidence is detached and tombstoned after identity deletion.',
);
$assert(
    hash_equals($storedAccountPseudonym, $transaction['account_token_pseudonym']),
    'Deletion preserves the transaction pseudonym already used for Apple reconciliation.',
);
$assert($transaction['status'] === 'refunded' && (int) $transaction['credited_coins'] === 100, 'Refund and accounting evidence is preserved.');
$assert($count($database, 'storekit_notifications') === 1, 'App Store notification evidence is preserved.');
$assert($count($database, 'purchased_coin_lots', 'player_id IS NULL') === 1, 'Purchased coin lots remain but are detached.');
$assert($count($database, 'player_entitlement_sources', 'player_id IS NULL') === 1, 'Paid entitlement sources remain but are detached.');
$familyBinding = $database->query('SELECT * FROM player_storekit_family_bindings')->fetch();
$assert(
    is_array($familyBinding)
    && $familyBinding['player_id'] === null
    && $familyBinding['account_deleted_at'] !== null
    && hash_equals($familyPseudonym, $familyBinding['app_transaction_pseudonym']),
    'Family Sharing identity is retained only as a non-transferable tombstone.',
);
$allocation = $database->query(
    "SELECT * FROM coin_spend_allocations WHERE allocation_id = 'allocation-purchased'"
)->fetch();
$assert(
    is_array($allocation)
    && $allocation['player_id'] === null
    && $allocation['spend_event_id'] === null,
    'Purchased spend evidence is detached from identity and the deleted aggregate ledger.',
);
$assert(hash_equals($expectedDeletionPseudonym, $allocation['spend_reference_pseudonym']), 'Retained purchased spend evidence uses the deletion pseudonym.');
$assert($count($database, 'coin_spend_allocations', "source = 'earned'") === 0, 'Ordinary earned-value spend history is removed.');
$earnedSettlement = $database->query(
    "SELECT player_id, source_reference FROM storekit_refund_debt_allocations WHERE allocation_id = 'refund-earned'"
)->fetch();
$assert(
    is_array($earnedSettlement)
    && $earnedSettlement['player_id'] === null
    && $earnedSettlement['source_reference'] !== 'earned-run-reference'
    && preg_match('/^[a-f0-9]{64}$/D', $earnedSettlement['source_reference']) === 1,
    'Earned credit retained only as pseudonymized refund-settlement evidence.',
);
$assert($count($database, 'storekit_refund_debt_allocations', 'player_id IS NULL') === 2, 'All exact refund-debt settlements remain detached.');
$assert($count($database, 'storekit_refund_cosmetics', 'player_id IS NULL') === 1, 'Refund-revoked cosmetic evidence remains detached.');
$assert($count($database, 'storekit_cosmetic_restore_debts', 'player_id IS NULL') === 1, 'Cosmetic restore debt evidence remains detached.');

$notFound = false;
try {
    $service->delete($target);
} catch (ApiException $error) {
    $notFound = $error->status === 401;
}
$assert($notFound, 'A deleted or stale session identity cannot delete twice.');

$insert($database, 'INSERT INTO player_storekit_bindings VALUES (:player, :token)', ['player' => $bare, 'token' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);
$bareResult = (new AccountDeletionService($database, ''))->delete($bare);
$assert($bareResult['deleted'] === true, 'A profile with no paid evidence can be deleted before retention keys are configured.');

$failedToken = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$insert($database, 'INSERT INTO player_sessions VALUES (:session, :player)', ['session' => 'session-failed', 'player' => $failed]);
$insert($database, 'INSERT INTO player_storekit_bindings VALUES (:player, :token)', ['player' => $failed, 'token' => $failedToken]);
$failedTransactionInsert = $database->prepare(
    'INSERT INTO storekit_transactions '
    . '(transaction_id, player_id, account_token_pseudonym, product_id, bundle_id, environment, '
    . 'status, credited_coins, payload_hash) VALUES '
    . "('apple-transaction-failed', :player, :pseudonym, 'coins.small', "
    . "'com.otcsoftware.pimpopom', 'Sandbox', 'active', 10, :hash)"
);
$failedTransactionInsert->bindValue(':player', $failed);
$failedTransactionInsert->bindValue(':pseudonym', str_repeat("\x44", 32), PDO::PARAM_LOB);
$failedTransactionInsert->bindValue(':hash', random_bytes(32), PDO::PARAM_LOB);
$failedTransactionInsert->execute();
$insert($database, 'INSERT INTO run_attempts VALUES (:run, :player)', ['run' => 'attempt-failed', 'player' => $failed]);
$database->exec(
    "CREATE TRIGGER block_failed_account_delete BEFORE DELETE ON players "
    . "WHEN OLD.id = '$failed' BEGIN SELECT RAISE(ABORT, 'simulated failure'); END"
);
$failedTransaction = false;
try {
    $service->delete($failed);
} catch (PDOException) {
    $failedTransaction = true;
}
$assert($failedTransaction, 'A failure during player deletion is surfaced.');
$assert($count($database, 'players', 'id = :id', ['id' => $failed]) === 1, 'A failed deletion rolls back the identity removal.');
$assert($count($database, 'player_sessions', 'player_id = :player', ['player' => $failed]) === 1, 'A failed deletion rolls back session removal.');
$assert($count($database, 'run_attempts', 'player_id = :player', ['player' => $failed]) === 1, 'A failed deletion rolls back proof-history removal.');
$rolledBackTransaction = $database->query(
    "SELECT player_id, account_deleted_at, account_token_pseudonym FROM storekit_transactions "
    . "WHERE transaction_id = 'apple-transaction-failed'"
)->fetch();
$assert(
    is_array($rolledBackTransaction)
    && $rolledBackTransaction['player_id'] === $failed
    && $rolledBackTransaction['account_deleted_at'] === null
    && hash_equals(str_repeat("\x44", 32), $rolledBackTransaction['account_token_pseudonym']),
    'A failed deletion rolls back StoreKit detachment and tombstoning.',
);

$shortKeyRejected = false;
try {
    new AccountDeletionService($database, 'too-short');
} catch (InvalidArgumentException) {
    $shortKeyRejected = true;
}
$assert($shortKeyRejected, 'A low-entropy retention HMAC key is rejected.');

fwrite(STDOUT, "Account deletion tests passed ({$assertions} assertions).\n");
