<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\GameCenterCatalog;
use SpeedyTapper\GameCenterPublicationRepository;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throwsStatus = static function (
    int $status,
    callable $callback,
    string $message,
) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $error) {
        $assert($error->status === $status, $message);
        return;
    }
    $assert(false, $message);
};

$database = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(
    'CREATE TABLE players ('
    . 'id TEXT PRIMARY KEY, coins INTEGER NOT NULL DEFAULT 0'
    . ')'
);
$database->exec(
    'CREATE TABLE player_game_center_bindings ('
    . 'player_id TEXT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE, '
    . 'team_player_id_hash BLOB NOT NULL UNIQUE, '
    . 'game_player_id_hash BLOB NULL UNIQUE, '
    . 'game_player_id_ciphertext BLOB NULL, game_player_id_iv BLOB NULL, '
    . 'game_player_id_tag BLOB NULL, linked_at TEXT NOT NULL, '
    . 'last_verified_at TEXT NOT NULL, publication_enabled_at TEXT NULL, '
    . 'publication_disabled_at TEXT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE leaderboard_entries ('
    . 'id TEXT PRIMARY KEY, player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'mode TEXT NOT NULL, score INTEGER NOT NULL, verification_status TEXT NOT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE multiplayer_results ('
    . 'id TEXT PRIMARY KEY, player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'score INTEGER NOT NULL, verification_status TEXT NOT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE player_achievements ('
    . 'player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'achievement_key TEXT NOT NULL, PRIMARY KEY (player_id, achievement_key)'
    . ')'
);
$database->exec(
    'CREATE TABLE game_center_assertion_uses ('
    . 'assertion_hash BLOB PRIMARY KEY, consumed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
    . 'expires_at TEXT NOT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE game_center_publication_outbox ('
    . 'id TEXT PRIMARY KEY, player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'publication_kind TEXT NOT NULL, vendor_identifier TEXT NOT NULL, '
    . 'pre_released INTEGER NOT NULL, desired_value INTEGER NULL, delivered_value INTEGER NULL, '
    . 'desired_revision INTEGER NOT NULL DEFAULT 1, state TEXT NOT NULL DEFAULT \'pending\', '
    . 'attempt_count INTEGER NOT NULL DEFAULT 0, available_at TEXT NOT NULL, '
    . 'lock_token TEXT NULL, locked_at TEXT NULL, apple_submission_id TEXT NULL, '
    . 'last_http_status INTEGER NULL, last_error_code TEXT NULL, last_error TEXT NULL, '
    . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, delivered_at TEXT NULL, '
    . 'UNIQUE (player_id, publication_kind, vendor_identifier, pre_released)'
    . ')'
);

$players = [
    '11111111-1111-4111-8111-111111111111',
    '22222222-2222-4222-8222-222222222222',
    '33333333-3333-4333-8333-333333333333',
    '44444444-4444-4444-8444-444444444444',
];
$insertPlayer = $database->prepare('INSERT INTO players (id, coins) VALUES (:id, :coins)');
foreach ($players as $index => $playerId) {
    $insertPlayer->execute(['id' => $playerId, 'coins' => ($index + 1) * 10]);
}
$database->exec(
    "INSERT INTO leaderboard_entries VALUES "
    . "('score-a', '{$players[0]}', 'normal', 111, 'verified'), "
    . "('score-c', '{$players[2]}', 'normal', 333, 'verified')"
);
$database->exec(
    "INSERT INTO player_achievements VALUES "
    . "('{$players[0]}', 'complete_arcade'), "
    . "('{$players[2]}', 'buy_a_pet')"
);

$repository = new GameCenterPublicationRepository(
    $database,
    str_repeat('game-center-autolink-secret-', 2),
    true,
);
$teamHash = static fn (string $id): string => hash(
    'sha256',
    "game_center\0" . $id,
    true,
);
$proofHash = static fn (string $id): string => hash(
    'sha256',
    "autolink-proof\0" . $id,
    true,
);
$assign = static function (
    string $playerId,
    string $teamId,
    string $gameId,
    string $proof,
) use ($repository, $teamHash, $proofHash): array {
    return $repository->assignCurrentProfile(
        $playerId,
        $teamHash($teamId),
        $gameId,
        $proofHash($proof),
        gmdate('Y-m-d H:i:s', time() + 600),
    );
};

$first = $assign($players[0], 'T:one', 'G:one', 'first');
$assert(
    $first === [
        'enabled' => true,
        'linked' => true,
        'newlyBound' => true,
        'reassigned' => false,
    ],
    'An authenticated current profile receives its first persistent pair.',
);
$throwsStatus(
    409,
    static fn (): array => $assign($players[0], 'T:one', 'G:one', 'first'),
    'A Game Center assertion remains single use after auto-linking.',
);
$database->exec(
    "UPDATE game_center_publication_outbox SET state = 'succeeded', "
    . 'delivered_value = desired_value, desired_revision = 9, attempt_count = 4, '
    . "apple_submission_id = 'kept-submission', delivered_at = CURRENT_TIMESTAMP "
    . "WHERE player_id = '{$players[0]}'"
);
$same = $assign($players[0], 'T:one', 'G:one', 'same-pair');
$assert(
    !$same['reassigned']
        && !$same['newlyBound']
        && (int) $database->query(
            "SELECT COUNT(*) FROM game_center_publication_outbox "
            . "WHERE player_id = '{$players[0]}' AND state = 'succeeded' "
            . "AND desired_revision = 9 AND apple_submission_id = 'kept-submission'"
        )->fetchColumn() === 2,
    'An identical fresh proof preserves succeeded delivery evidence.',
);
$database->exec(
    "UPDATE player_game_center_bindings SET game_player_id_ciphertext = NULL "
    . "WHERE player_id = '{$players[0]}'"
);
$repaired = $assign($players[0], 'T:one', 'G:one', 'repair-incomplete-ciphertext');
$assert(
    !$repaired['reassigned']
        && $repaired['newlyBound']
        && is_string($database->query(
            "SELECT game_player_id_ciphertext FROM player_game_center_bindings "
            . "WHERE player_id = '{$players[0]}'"
        )->fetchColumn()),
    'A matching but incomplete encrypted destination is safely rebuilt instead of misreported active.',
);

$assign($players[1], 'T:two', 'G:two', 'second-owner');
$assign($players[2], 'T:three', 'G:three', 'current-old-pair');
$priorGameCiphertext = $database->query(
    "SELECT game_player_id_ciphertext FROM player_game_center_bindings "
    . "WHERE player_id = '{$players[1]}'"
)->fetchColumn();
$database->exec(
    "UPDATE game_center_publication_outbox SET state = 'processing', attempt_count = 8, "
    . "lock_token = 'old-lock', locked_at = CURRENT_TIMESTAMP, "
    . "apple_submission_id = 'old-apple-id', delivered_at = CURRENT_TIMESTAMP, "
    . "last_http_status = 503, last_error_code = 'OLD_ERROR', last_error = 'old error' "
    . "WHERE player_id IN ('{$players[0]}','{$players[2]}')"
);
$customJob = $database->prepare(
    'INSERT INTO game_center_publication_outbox '
    . '(id, player_id, publication_kind, vendor_identifier, pre_released, '
    . 'desired_value, delivered_value, desired_revision, state, attempt_count, '
    . 'available_at, lock_token, locked_at, apple_submission_id, last_http_status, '
    . 'last_error_code, last_error, created_at, updated_at, delivered_at) VALUES '
    . "(:id, :player_id, 'achievement', :vendor, 0, 100, 100, 5, 'processing', 7, "
    . "CURRENT_TIMESTAMP, 'stale-token', CURRENT_TIMESTAMP, 'stale-apple', 500, "
    . "'STALE', 'stale error', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
);
foreach ([$players[1], $players[2]] as $index => $playerId) {
    $customJob->execute([
        'id' => '90000000-0000-4000-8000-00000000000' . ($index + 1),
        'player_id' => $playerId,
        'vendor' => 'test.autolink.' . $index,
    ]);
}

$split = $assign($players[2], 'T:one', 'G:two', 'split-owners');
$assert(
    $split['reassigned'] && $split['linked'] && $split['newlyBound'],
    'The current profile wins when team and game hashes have different prior owners.',
);
$bindings = $database->query(
    'SELECT player_id FROM player_game_center_bindings ORDER BY player_id'
)->fetchAll(PDO::FETCH_COLUMN);
$assert(
    $bindings === [$players[2]],
    'Submitted owners and the current old binding are removed without reverse association.',
);
$currentBinding = $database->query(
    "SELECT * FROM player_game_center_bindings WHERE player_id = '{$players[2]}'"
)->fetch();
$assert(
    is_array($currentBinding)
        && is_string($priorGameCiphertext)
        && !hash_equals($priorGameCiphertext, (string) $currentBinding['game_player_id_ciphertext']),
    'Reassignment creates fresh ciphertext for the final player and team associated data.',
);
$cancelled = $database->query(
    "SELECT * FROM game_center_publication_outbox "
    . "WHERE vendor_identifier LIKE 'test.autolink.%' ORDER BY player_id"
)->fetchAll();
$assert(
    count($cancelled) === 2
        && array_reduce(
            $cancelled,
            static fn (bool $valid, array $job): bool =>
                $valid
                && $job['desired_value'] === null
                && $job['delivered_value'] === null
                && $job['state'] === 'cancelled'
                && (int) $job['attempt_count'] === 0
                && $job['lock_token'] === null
                && $job['locked_at'] === null
                && $job['apple_submission_id'] === null
                && $job['delivered_at'] === null
                && $job['last_http_status'] === null
                && $job['last_error_code'] === null
                && $job['last_error'] === null,
            true,
        ),
    'Displaced and current outboxes are revision-cancelled with all stale fields cleared.',
);
$currentDesired = $database->query(
    "SELECT publication_kind, desired_value, delivered_value, state, apple_submission_id "
    . "FROM game_center_publication_outbox WHERE player_id = '{$players[2]}' "
    . 'AND pre_released = 1 ORDER BY publication_kind'
)->fetchAll();
$assert(
    count($currentDesired) === 2
        && array_reduce(
            $currentDesired,
            static fn (bool $valid, array $job): bool =>
                $valid
                && $job['state'] === 'pending'
                && $job['desired_value'] !== null
                && $job['delivered_value'] === null
                && $job['apple_submission_id'] === null,
            true,
        ),
    'Only the current profile is backfilled after real destination reassignment.',
);
$assert(
    $database->query(
        "SELECT GROUP_CONCAT(coins ORDER BY id) FROM players"
    )->fetchColumn() === '10,20,30,40',
    'Current-profile-wins never merges or moves wallet data.',
);

$lease = $repository->claimNext();
$assert(is_array($lease), 'The new destination has claimable authoritative work.');
$assign($players[3], 'T:one', 'G:two', 'lease-reassignment');
$assert(
    $repository->prepareClaimForDelivery($lease) === null
        && !$repository->markSucceeded($lease, 'must-not-ack')
        && !$repository->markFailed($lease, new RuntimeException('must-not-retry')),
    'A stale worker lease cannot publish, acknowledge, retry, or overwrite a reassigned destination.',
);

$source = (string) file_get_contents(
    dirname(__DIR__) . '/server/src/GameCenterPublicationRepository.php'
);
$assert(
    str_contains($source, 'withPlayerPublicationLocks')
        && str_contains($source, 'sort($playerIds, SORT_STRING)')
        && str_contains($source, 'array_reverse($acquired)')
        && str_contains($source, 'SELECT id FROM game_center_publication_outbox')
        && str_contains($source, 'desired_revision = desired_revision + 1'),
    'Reassignment uses deterministic publication locks and revision-fences every outbox.',
);

fwrite(STDOUT, "Game Center auto-link checks passed ({$assertions} assertions).\n");
