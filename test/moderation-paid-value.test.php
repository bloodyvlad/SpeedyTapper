<?php

declare(strict_types=1);

use SpeedyTapper\LeaderboardModerationService;

require dirname(__DIR__) . '/server/autoload.php';

final class ModerationSqlitePdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = preg_replace('/\s+FOR UPDATE\b/i', '', $query) ?? $query;
        $query = preg_replace('/\bUTC_TIMESTAMP\(3\)/i', 'CURRENT_TIMESTAMP', $query) ?? $query;
        $query = preg_replace('/\bLEAST\(/i', 'MIN(', $query) ?? $query;
        return parent::prepare($query, $options);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$database = new ModerationSqlitePdo();
$database->exec(<<<'SQL'
CREATE TABLE storekit_transactions (
    transaction_id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL,
    refund_debt_outstanding INTEGER NOT NULL,
    base_refund_debt_outstanding INTEGER NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE storekit_cosmetic_restore_debts (
    debt_id TEXT PRIMARY KEY,
    refund_transaction_id TEXT NOT NULL,
    player_id TEXT NOT NULL,
    amount INTEGER NOT NULL,
    settled_amount INTEGER NOT NULL,
    released_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE storekit_refund_debt_allocations (
    allocation_id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_reference TEXT NOT NULL,
    source_economy_generation INTEGER NOT NULL,
    source_purchase_transaction_id TEXT NULL,
    refund_transaction_id TEXT NOT NULL,
    cosmetic_restore_debt_id TEXT NULL,
    amount INTEGER NOT NULL,
    released_amount INTEGER NOT NULL DEFAULT 0,
    source_revoked_at TEXT NULL,
    released_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE players (
    id TEXT PRIMARY KEY,
    coins INTEGER NOT NULL,
    coin_debt INTEGER NOT NULL,
    earned_coins INTEGER NOT NULL,
    purchased_coins INTEGER NOT NULL,
    earned_coin_debt INTEGER NOT NULL,
    refund_coin_debt INTEGER NOT NULL,
    total_coins_collected INTEGER NOT NULL,
    coin_time_remainder_ms INTEGER NOT NULL,
    total_play_ms INTEGER NOT NULL,
    economy_generation INTEGER NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE completed_runs (
    run_id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL,
    economy_generation INTEGER NOT NULL,
    verification_status TEXT NOT NULL,
    coin_status TEXT NOT NULL,
    duration_ms INTEGER NOT NULL,
    credited_play_ms INTEGER NULL,
    server_elapsed_ms INTEGER NULL
);
CREATE TABLE coin_ledger (
    event_id TEXT PRIMARY KEY,
    event_key TEXT NOT NULL,
    player_id TEXT NOT NULL,
    economy_generation INTEGER NOT NULL,
    run_id TEXT NULL,
    event_type TEXT NOT NULL,
    play_ms_delta INTEGER NOT NULL,
    coin_delta INTEGER NOT NULL,
    remainder_before_ms INTEGER NOT NULL,
    remainder_after_ms INTEGER NOT NULL,
    earned_delta INTEGER NOT NULL,
    purchased_delta INTEGER NOT NULL,
    coin_balance_after INTEGER NOT NULL,
    earned_balance_after INTEGER NOT NULL,
    purchased_balance_after INTEGER NOT NULL,
    coin_debt_after INTEGER NOT NULL,
    earned_debt_after INTEGER NOT NULL,
    refund_debt_after INTEGER NOT NULL,
    total_play_ms_after INTEGER NOT NULL,
    coin_status TEXT NOT NULL,
    actor TEXT NULL,
    reason TEXT NULL
);
CREATE TABLE coin_spend_allocations (
    allocation_id TEXT PRIMARY KEY,
    spend_event_id TEXT NULL,
    player_id TEXT NOT NULL,
    source TEXT NOT NULL,
    released_at TEXT NULL,
    amount INTEGER NOT NULL
);
SQL);
$playerId = '11111111-1111-4111-8111-111111111111';
$transaction = $database->prepare(
    'INSERT INTO storekit_transactions '
    . '(transaction_id, player_id, refund_debt_outstanding, base_refund_debt_outstanding) '
    . 'VALUES (:transaction_id, :player_id, :outstanding, :base_outstanding)'
);
$transaction->execute([
    'transaction_id' => 'base-refund',
    'player_id' => $playerId,
    'outstanding' => 0,
    'base_outstanding' => 0,
]);
$transaction->execute([
    'transaction_id' => 'cosmetic-refund',
    'player_id' => $playerId,
    'outstanding' => 0,
    'base_outstanding' => 0,
]);
$database->prepare(
    'INSERT INTO storekit_cosmetic_restore_debts '
    . '(debt_id, refund_transaction_id, player_id, amount, settled_amount) '
    . "VALUES ('cosmetic-debt', 'cosmetic-refund', :player_id, 5, 5)"
)->execute(['player_id' => $playerId]);
$insert = $database->prepare(
    'INSERT INTO storekit_refund_debt_allocations '
    . '(allocation_id, player_id, source_type, source_reference, source_economy_generation, '
    . 'refund_transaction_id, cosmetic_restore_debt_id, amount) VALUES '
    . '(:allocation_id, :player_id, \'earned_credit\', :source_reference, 0, '
    . ':refund_transaction_id, :cosmetic_restore_debt_id, :amount)'
);
$insert->execute([
    'allocation_id' => 'base-allocation',
    'player_id' => $playerId,
    'source_reference' => 'run:base-run',
    'refund_transaction_id' => 'base-refund',
    'cosmetic_restore_debt_id' => null,
    'amount' => 3,
]);
$insert->execute([
    'allocation_id' => 'cosmetic-allocation',
    'player_id' => $playerId,
    'source_reference' => 'run:cosmetic-run',
    'refund_transaction_id' => 'cosmetic-refund',
    'cosmetic_restore_debt_id' => 'cosmetic-debt',
    'amount' => 5,
]);

$service = new LeaderboardModerationService($database);
$method = new ReflectionMethod($service, 'syncEarnedRefundDebtSettlements');
$sync = static fn (string $runId, int $generation, string $status): int => $method->invoke(
    $service,
    $playerId,
    $runId,
    $generation,
    $status,
);
$row = static function (PDO $database, string $table, string $idColumn, string $id): array {
    $statement = $database->prepare('SELECT * FROM ' . $table . ' WHERE ' . $idColumn . ' = :id');
    $statement->execute(['id' => $id]);
    return $statement->fetch() ?: [];
};

$assert($sync('base-run', 0, 'revoked') === 3, 'Revoking an earned run reopens its exact base refund debt.');
$base = $row($database, 'storekit_transactions', 'transaction_id', 'base-refund');
$baseAllocation = $row($database, 'storekit_refund_debt_allocations', 'allocation_id', 'base-allocation');
$assert(
    (int) $base['refund_debt_outstanding'] === 3
    && (int) $base['base_refund_debt_outstanding'] === 3
    && $baseAllocation['source_revoked_at'] !== null,
    'Base-debt and settlement provenance change together during moderation.',
);
$assert($sync('base-run', 0, 'revoked') === 0, 'Repeated run revocation is idempotent.');
$assert($sync('base-run', 0, 'eligible') === -3, 'Restoring the same run settles the same base debt again.');
$base = $row($database, 'storekit_transactions', 'transaction_id', 'base-refund');
$assert(
    (int) $base['refund_debt_outstanding'] === 0
    && (int) $base['base_refund_debt_outstanding'] === 0,
    'Run restoration cannot leave aggregate or base refund debt behind.',
);

$assert(
    $sync('cosmetic-run', 0, 'revoked') === 5,
    'Revoking an earned settlement reopens its exact cosmetic-restore debt.',
);
$cosmetic = $row($database, 'storekit_transactions', 'transaction_id', 'cosmetic-refund');
$cosmeticDebt = $row($database, 'storekit_cosmetic_restore_debts', 'debt_id', 'cosmetic-debt');
$assert(
    (int) $cosmetic['refund_debt_outstanding'] === 5
    && (int) $cosmeticDebt['settled_amount'] === 0,
    'Cosmetic component and transaction aggregate remain synchronized.',
);
$assert($sync('cosmetic-run', 0, 'eligible') === -5, 'Restoring the run re-settles its cosmetic debt.');

$assert(
    $sync('admin-reset:any', 1, 'revoked') === 8,
    'Advancing the economy generation reopens every active earned refund settlement.',
);
$assert(
    (int) $database->query(
        'SELECT SUM(refund_debt_outstanding) FROM storekit_transactions'
    )->fetchColumn() === 8,
    'An administrative earned-value reset cannot silently retain debt repayments from the old generation.',
);

// A later credit can settle debt reopened while a run is revoked. Restoring
// the older run must settle only what remains and release the surplus instead
// of failing or displacing the later valid settlement.
$database->prepare(
    'UPDATE storekit_transactions SET refund_debt_outstanding = 1, '
    . 'base_refund_debt_outstanding = 1 WHERE transaction_id = :transaction_id'
)->execute(['transaction_id' => 'base-refund']);
$insert->execute([
    'allocation_id' => 'later-base-allocation',
    'player_id' => $playerId,
    'source_reference' => 'run:later-base-run',
    'refund_transaction_id' => 'base-refund',
    'cosmetic_restore_debt_id' => null,
    'amount' => 2,
]);
$assert(
    $sync('base-run', 0, 'eligible') === -1,
    'Restoring an older run settles only refund debt left after a later valid credit.',
);
$base = $row($database, 'storekit_transactions', 'transaction_id', 'base-refund');
$baseAllocation = $row(
    $database,
    'storekit_refund_debt_allocations',
    'allocation_id',
    'base-allocation',
);
$assert(
    (int) $base['refund_debt_outstanding'] === 0
    && (int) $base['base_refund_debt_outstanding'] === 0
    && (int) $baseAllocation['released_amount'] === 2
    && $baseAllocation['released_at'] === null
    && $baseAllocation['source_revoked_at'] === null,
    'The restored credit retains exact active settlement provenance and releases only its two-coin surplus.',
);

// Approving a previously withheld run creates a new earned credit without a
// pre-existing allocation. It must use the same exact debt allocator as a
// normally verified run before any increase can become spendable.
$approvalPlayerId = '22222222-2222-4222-8222-222222222222';
$approvalRunId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$database->prepare(
    'INSERT INTO players '
    . '(id, coins, coin_debt, earned_coins, purchased_coins, earned_coin_debt, '
    . 'refund_coin_debt, total_coins_collected, coin_time_remainder_ms, total_play_ms, '
    . 'economy_generation) VALUES (:player_id, 0, 3, 0, 0, 0, 3, 0, 0, 0, 0)'
)->execute(['player_id' => $approvalPlayerId]);
$database->prepare(
    'INSERT INTO completed_runs '
    . '(run_id, player_id, economy_generation, verification_status, coin_status, '
    . 'duration_ms, credited_play_ms, server_elapsed_ms) VALUES '
    . "(:run_id, :player_id, 0, 'verified', 'eligible', 120000, 120000, 120000)"
)->execute(['run_id' => $approvalRunId, 'player_id' => $approvalPlayerId]);
$transaction->execute([
    'transaction_id' => 'approval-refund',
    'player_id' => $approvalPlayerId,
    'outstanding' => 3,
    'base_outstanding' => 3,
]);
$recompute = new ReflectionMethod($service, 'recomputePlayerCoins');
$approvalResult = $recompute->invoke(
    $service,
    $approvalPlayerId,
    $approvalRunId,
    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'moderation_reconcile',
    'eligible',
    'admin:test',
    'Approved a reviewed run.',
);
$approval = $row($database, 'storekit_transactions', 'transaction_id', 'approval-refund');
$approvalPlayer = $row($database, 'players', 'id', $approvalPlayerId);
$approvalLedger = $database->query(
    "SELECT coin_delta, refund_debt_after FROM coin_ledger "
    . "WHERE run_id = '{$approvalRunId}'"
)->fetch();
$approvalAllocation = $database->query(
    "SELECT source_type, source_reference, source_economy_generation, refund_transaction_id, amount "
    . "FROM storekit_refund_debt_allocations WHERE source_reference = 'run:{$approvalRunId}'"
)->fetch();
$assert(
    $approvalResult['coinBalance'] === 0
    && $approvalResult['coinDebt'] === 1
    && (int) $approvalPlayer['earned_coins'] === 0
    && (int) $approvalPlayer['refund_coin_debt'] === 1
    && (int) $approval['refund_debt_outstanding'] === 1
    && (int) $approval['base_refund_debt_outstanding'] === 1
    && $approvalLedger === [
        'coin_delta' => 2,
        'refund_debt_after' => 1,
    ],
    'A newly approved two-coin credit clears two coins of existing refund debt before becoming spendable.',
);
$assert(
    $approvalAllocation === [
        'source_type' => 'earned_credit',
        'source_reference' => 'run:' . $approvalRunId,
        'source_economy_generation' => 0,
        'refund_transaction_id' => 'approval-refund',
        'amount' => 2,
    ],
    'Moderation retains the approved run and exact refund transaction as settlement provenance.',
);
$repeatApproval = $recompute->invoke(
    $service,
    $approvalPlayerId,
    $approvalRunId,
    'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    'manual_reconcile',
    'eligible',
    'admin:test',
    'Repeated reconciliation.',
);
$assert(
    $repeatApproval['coinDelta'] === 0
    && $repeatApproval['coinBalance'] === 0
    && $repeatApproval['coinDebt'] === 1
    && (int) $database->query(
        "SELECT COUNT(*) FROM storekit_refund_debt_allocations "
        . "WHERE source_reference = 'run:{$approvalRunId}'"
    )->fetchColumn() === 1,
    'Repeating approved-run reconciliation is idempotent and cannot settle the same credit twice.',
);

fwrite(STDOUT, "Paid-value moderation tests passed ({$assertions} assertions).\n");
