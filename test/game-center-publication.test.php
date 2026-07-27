<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\AchievementService;
use SpeedyTapper\AppStoreConnectGameCenterClient;
use SpeedyTapper\CoinWalletRepository;
use SpeedyTapper\Config;
use SpeedyTapper\GameCenterAppleApiException;
use SpeedyTapper\GameCenterCatalog;
use SpeedyTapper\GameCenterOutboxWorker;
use SpeedyTapper\GameCenterPublicationRepository;
use SpeedyTapper\GameCenterSubmissionClient;
use SpeedyTapper\LeaderboardModerationService;

require dirname(__DIR__) . '/server/autoload.php';

final class GameCenterPublicationSqlitePdo extends PDO
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
        $query = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $query)
            ?? $query;
        $query = preg_replace('/\s+FOR UPDATE\b/i', '', $query) ?? $query;
        $query = preg_replace('/\bUTC_TIMESTAMP\(3\)/i', 'CURRENT_TIMESTAMP', $query)
            ?? $query;
        $query = preg_replace('/\bLEAST\(/i', 'MIN(', $query) ?? $query;
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
$decode = static function (string $value): string {
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(
        strtr($value, '-_', '+/') . str_repeat('=', $padding),
        true,
    );
    if (!is_string($decoded)) {
        throw new RuntimeException('Invalid JWT fixture encoding.');
    }
    return $decoded;
};
$rawSignatureToDer = static function (string $raw): string {
    if (strlen($raw) !== 64) {
        throw new RuntimeException('Expected an ES256 signature.');
    }
    $integer = static function (string $value): string {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . chr(strlen($value)) . $value;
    };
    $body = $integer(substr($raw, 0, 32)) . $integer(substr($raw, 32, 32));
    return "\x30" . chr(strlen($body)) . $body;
};

$database = new GameCenterPublicationSqlitePdo();
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(
    'CREATE TABLE players ('
    . 'id TEXT PRIMARY KEY, earned_coins INTEGER NOT NULL DEFAULT 0, '
    . 'purchased_coins INTEGER NOT NULL DEFAULT 0, earned_coin_debt INTEGER NOT NULL DEFAULT 0, '
    . 'refund_coin_debt INTEGER NOT NULL DEFAULT 0, coins INTEGER NOT NULL DEFAULT 0, '
    . 'coin_debt INTEGER NOT NULL DEFAULT 0, total_play_ms INTEGER NOT NULL DEFAULT 0, '
    . 'total_coins_collected INTEGER NOT NULL DEFAULT 0, '
    . 'coin_time_remainder_ms INTEGER NOT NULL DEFAULT 0, '
    . 'economy_generation INTEGER NOT NULL DEFAULT 0, '
    . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
    . ')'
);
$database->exec(
    'CREATE TABLE player_game_center_bindings ('
    . 'player_id TEXT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE, '
    . 'team_player_id_hash BLOB NOT NULL UNIQUE, '
    . 'game_player_id_hash BLOB NULL UNIQUE, '
    . 'game_player_id_ciphertext BLOB NULL, '
    . 'game_player_id_iv BLOB NULL, '
    . 'game_player_id_tag BLOB NULL, '
    . 'linked_at TEXT NOT NULL, last_verified_at TEXT NOT NULL, '
    . 'publication_enabled_at TEXT NULL, publication_disabled_at TEXT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE leaderboard_entries ('
    . 'id TEXT PRIMARY KEY, player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'mode TEXT NOT NULL, score INTEGER NOT NULL, verification_status TEXT NOT NULL, '
    . 'godlike_count INTEGER NOT NULL DEFAULT 0, moderated_at TEXT NULL, '
    . 'moderated_by TEXT NULL, moderation_reason TEXT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE completed_runs ('
    . 'run_id TEXT PRIMARY KEY, player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'leaderboard_entry_id TEXT NULL REFERENCES leaderboard_entries(id) ON DELETE CASCADE, '
    . 'economy_generation INTEGER NOT NULL DEFAULT 0, mode TEXT NOT NULL, score INTEGER NOT NULL, '
    . 'verification_status TEXT NOT NULL, coin_status TEXT NOT NULL, '
    . 'duration_ms INTEGER NOT NULL DEFAULT 0, credited_play_ms INTEGER NULL, '
    . 'server_elapsed_ms INTEGER NULL, moderated_at TEXT NULL, moderated_by TEXT NULL, '
    . 'moderation_reason TEXT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE player_achievements ('
    . 'player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'achievement_key TEXT NOT NULL, reward_coins INTEGER NOT NULL DEFAULT 0, '
    . 'PRIMARY KEY (player_id, achievement_key)'
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
$database->exec(
    'CREATE TABLE coin_ledger ('
    . 'event_id TEXT PRIMARY KEY, event_key TEXT NOT NULL, player_id TEXT NOT NULL, '
    . 'economy_generation INTEGER NOT NULL, run_id TEXT NULL, event_type TEXT NOT NULL, '
    . 'play_ms_delta INTEGER NOT NULL, coin_delta INTEGER NOT NULL, '
    . 'remainder_before_ms INTEGER NOT NULL, remainder_after_ms INTEGER NOT NULL, '
    . 'earned_delta INTEGER NOT NULL, purchased_delta INTEGER NOT NULL, '
    . 'coin_balance_after INTEGER NOT NULL, earned_balance_after INTEGER NOT NULL, '
    . 'purchased_balance_after INTEGER NOT NULL, coin_debt_after INTEGER NOT NULL, '
    . 'earned_debt_after INTEGER NOT NULL, refund_debt_after INTEGER NOT NULL, '
    . 'total_play_ms_after INTEGER NOT NULL, coin_status TEXT NOT NULL, '
    . 'actor TEXT NULL, reason TEXT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE coin_spend_allocations ('
    . 'allocation_id TEXT PRIMARY KEY, spend_event_id TEXT NULL, player_id TEXT NOT NULL, '
    . 'source TEXT NOT NULL, amount INTEGER NOT NULL DEFAULT 0, released_at TEXT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE storekit_refund_debt_allocations ('
    . 'allocation_id TEXT PRIMARY KEY, player_id TEXT NOT NULL, source_type TEXT NOT NULL, '
    . 'source_reference TEXT NOT NULL, source_economy_generation INTEGER NOT NULL, '
    . 'refund_transaction_id TEXT NOT NULL, cosmetic_restore_debt_id TEXT NULL, '
    . 'amount INTEGER NOT NULL, released_amount INTEGER NOT NULL DEFAULT 0, '
    . 'source_revoked_at TEXT NULL, released_at TEXT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE leaderboard_moderation_events ('
    . 'event_id TEXT PRIMARY KEY, leaderboard_entry_id TEXT NOT NULL, completed_run_id TEXT NULL, '
    . 'player_id TEXT NOT NULL, action TEXT NOT NULL, from_status TEXT NOT NULL, '
    . 'to_status TEXT NOT NULL, from_coin_status TEXT NULL, to_coin_status TEXT NULL, '
    . 'actor TEXT NOT NULL, reason TEXT NOT NULL, details_json TEXT NOT NULL'
    . ')'
);

$playerOne = '11111111-1111-4111-8111-111111111111';
$playerTwo = '22222222-2222-4222-8222-222222222222';
$database->exec(
    "INSERT INTO players (id) VALUES ('{$playerOne}'), ('{$playerTwo}')"
);
$teamOne = hash('sha256', "game_center\0T:one", true);
$teamTwo = hash('sha256', "game_center\0T:two", true);
$binding = $database->prepare(
    'INSERT INTO player_game_center_bindings '
    . '(player_id, team_player_id_hash, linked_at, last_verified_at) '
    . 'VALUES (:player_id, :team_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
);
$binding->bindValue(':player_id', $playerOne);
$binding->bindValue(':team_hash', $teamOne, PDO::PARAM_LOB);
$binding->execute();
$binding->bindValue(':player_id', $playerTwo);
$binding->bindValue(':team_hash', $teamTwo, PDO::PARAM_LOB);
$binding->execute();
$scores = $database->prepare(
    'INSERT INTO leaderboard_entries '
    . '(id, player_id, mode, score, verification_status) '
    . 'VALUES (:id, :player_id, :mode, :score, :status)'
);
foreach ([
    ['a', 'normal', 100, 'verified'],
    ['b', 'normal', 999_999, 'legacy'],
    ['c', 'normal', 250, 'review'],
    ['d', 'zen', 500_000, 'verified'],
] as [$id, $mode, $score, $status]) {
    $scores->execute([
        'id' => $id,
        'player_id' => $playerOne,
        'mode' => $mode,
        'score' => $score,
        'status' => $status,
    ]);
}
$achievement = $database->prepare(
    'INSERT INTO player_achievements (player_id, achievement_key) '
    . 'VALUES (:player_id, :achievement_key)'
);
foreach (['complete_arcade', 'buy_a_pet', 'complete_zen'] as $achievementId) {
    $achievement->execute([
        'player_id' => $playerOne,
        'achievement_key' => $achievementId,
    ]);
}

$repository = new GameCenterPublicationRepository(
    $database,
    str_repeat('publication-secret-', 3),
    true,
);
$database->beginTransaction();
$enabled = $repository->enableInCurrentTransaction(
    $playerOne,
    $teamOne,
    'G:game-player-one',
);
$database->commit();
$assert(
    $enabled === ['enabled' => true, 'newlyBound' => true],
    'A recent verified team link can bind one client-asserted persistent gamePlayerID.',
);
$storedBinding = $database->query(
    "SELECT * FROM player_game_center_bindings WHERE player_id = '{$playerOne}'"
)->fetch();
$assert(
    is_array($storedBinding)
        && is_string($storedBinding['game_player_id_hash'])
        && strlen($storedBinding['game_player_id_hash']) === 32
        && is_string($storedBinding['game_player_id_ciphertext'])
        && !str_contains($storedBinding['game_player_id_ciphertext'], 'G:game-player-one'),
    'The scoped Game Center ID is uniqueness-hashed and encrypted, never stored as plaintext.',
);
$jobs = $database->query(
    "SELECT publication_kind, vendor_identifier, desired_value "
    . "FROM game_center_publication_outbox WHERE player_id = '{$playerOne}' "
    . 'ORDER BY publication_kind, vendor_identifier'
)->fetchAll();
$assert(
    count($jobs) === 3
        && array_values(array_filter(
            $jobs,
            static fn (array $job): bool =>
                $job['publication_kind'] === 'leaderboard'
                && (int) $job['desired_value'] === 100,
        )) !== [],
    'Initial linking backfills the verified-only Arcade best and allowlisted achievements while skipping retired rows.',
);
$status = $repository->status($playerOne);
$assert(
    $status['identityLinked']
        && $status['publicationEnabled']
        && $status['mirrorReady']
        && $status['preReleased']
        && $status['pendingJobs'] === 3,
    'Publication readiness is distinct from the underlying identity binding.',
);

$database->beginTransaction();
$again = $repository->enableInCurrentTransaction(
    $playerOne,
    $teamOne,
    'G:game-player-one',
);
$database->commit();
$assert(
    $again === ['enabled' => true, 'newlyBound' => false],
    'A fresh proof for the same one-to-one association is idempotent.',
);
$database->beginTransaction();
$throwsStatus(
    409,
    static fn () => $repository->enableInCurrentTransaction(
        $playerOne,
        $teamOne,
        'G:different-player',
    ),
    'A profile cannot silently replace its bound Game Center scoped player.',
);
$database->rollBack();
$database->beginTransaction();
$throwsStatus(
    409,
    static fn () => $repository->enableInCurrentTransaction(
        $playerTwo,
        $teamTwo,
        'G:game-player-one',
    ),
    'One Game Center scoped player cannot publish two PimPoPom wallets.',
);
$database->rollBack();

$scores->execute([
    'id' => 'e',
    'player_id' => $playerOne,
    'mode' => 'normal',
    'score' => 175,
    'status' => 'verified',
]);
$database->beginTransaction();
$repository->enqueueBestScoreInCurrentTransaction($playerOne);
$database->commit();
$leaderboardJob = $database->query(
    "SELECT * FROM game_center_publication_outbox WHERE player_id = '{$playerOne}' "
    . "AND publication_kind = 'leaderboard'"
)->fetch();
$assert(
    is_array($leaderboardJob)
        && (int) $leaderboardJob['desired_value'] === 175
        && (int) $leaderboardJob['desired_revision'] === 2,
    'A newer verified personal best supersedes the queued desired score.',
);

$submitted = [];
$client = new class($submitted) implements GameCenterSubmissionClient {
    /** @var list<array<string, mixed>> */
    public array $submitted = [];

    public function __construct(array &$submitted)
    {
        $this->submitted =& $submitted;
    }

    public function submitLeaderboard(
        string $scopedPlayerId,
        int $score,
        bool $preReleased,
    ): string {
        $this->submitted[] = compact('scopedPlayerId', 'score', 'preReleased');
        return 'leaderboard-submission';
    }

    public function submitAchievement(
        string $scopedPlayerId,
        string $achievementId,
        bool $preReleased,
    ): string {
        $this->submitted[] = compact('scopedPlayerId', 'achievementId', 'preReleased');
        return 'achievement-submission-' . $achievementId;
    }
};
$worker = new GameCenterOutboxWorker($repository, $client);
$workerResult = $worker->run(10);
$assert(
    $workerResult === ['claimed' => 3, 'delivered' => 3, 'superseded' => 0, 'failed' => 0]
        && count($submitted) === 3
        && count(array_filter(
            $submitted,
            static fn (array $item): bool =>
                ($item['score'] ?? null) === 175
                && ($item['scopedPlayerId'] ?? null) === 'G:game-player-one',
        )) === 1,
    'The worker decrypts only at dispatch, publishes the verified desired state, and marks success.',
);

$database->exec(
    "UPDATE leaderboard_entries SET verification_status = 'quarantined' WHERE id = 'e'"
);
$database->beginTransaction();
$repository->enqueueBestScoreInCurrentTransaction($playerOne);
$database->commit();
$lowerQueued = $database->query(
    "SELECT desired_value, delivered_value, state FROM game_center_publication_outbox "
    . "WHERE player_id = '{$playerOne}' AND publication_kind = 'leaderboard'"
)->fetch();
$lowerResult = $worker->run(1);
$assert(
    is_array($lowerQueued)
        && (int) $lowerQueued['desired_value'] === 100
        && (int) $lowerQueued['delivered_value'] === 175
        && $lowerQueued['state'] === 'pending'
        && $lowerResult === [
            'claimed' => 1,
            'delivered' => 1,
            'superseded' => 0,
            'failed' => 0,
        ]
        && ($submitted[3]['score'] ?? null) === 100,
    'A lower verified replacement is submitted because Apple overwrites the player score.',
);

$database->exec(
    "UPDATE leaderboard_entries SET verification_status = 'verified' WHERE id = 'e'"
);
$database->beginTransaction();
$repository->enqueueBestScoreInCurrentTransaction($playerOne);
$database->commit();
$assert(
    $worker->run(1)['delivered'] === 1
        && ($submitted[4]['score'] ?? null) === 175,
    'A restored verified best is published through the same desired-state path.',
);

$scores->execute([
    'id' => 'f',
    'player_id' => $playerOne,
    'mode' => 'normal',
    'score' => 300,
    'status' => 'verified',
]);
$database->beginTransaction();
$repository->enqueueBestScoreInCurrentTransaction($playerOne);
$database->commit();
$claimed = $repository->claimNext();
$assert(is_array($claimed), 'A new best creates a claimable publication revision.');
$database->exec(
    "UPDATE leaderboard_entries SET verification_status = 'quarantined' WHERE id IN ('e','f')"
);
$prepared = $repository->prepareClaimForDelivery($claimed);
$refreshed = $database->query(
    "SELECT desired_value, delivered_value, state FROM game_center_publication_outbox "
    . "WHERE player_id = '{$playerOne}' AND publication_kind = 'leaderboard'"
)->fetch();
$assert(
    $prepared === null
        && is_array($refreshed)
        && (int) $refreshed['desired_value'] === 100
        && (int) $refreshed['delivered_value'] === 175
        && $refreshed['state'] === 'pending'
        && count($submitted) === 5,
    'A stale claimed score is revalidated and superseded before any Apple request.',
);
$assert(
    $worker->run(1)['delivered'] === 1
        && ($submitted[5]['score'] ?? null) === 100,
    'The superseding lower desired score is delivered on the next bounded worker pass.',
);

$database->exec(
    "UPDATE leaderboard_entries SET verification_status = 'quarantined' "
    . "WHERE player_id = '{$playerOne}' AND mode = 'normal'"
);
$database->beginTransaction();
$repository->enqueueBestScoreInCurrentTransaction($playerOne);
$database->commit();
$assert(
    $database->query(
        "SELECT desired_value IS NULL AND state = 'needs_reset' "
        . "FROM game_center_publication_outbox WHERE player_id = '{$playerOne}' "
        . "AND publication_kind = 'leaderboard'"
    )->fetchColumn() == 1,
    'Removing every verified score surfaces an operator-visible Apple reset requirement.',
);

$repository->disable($playerOne);
$disabled = $repository->status($playerOne);
$assert(
    !$disabled['publicationEnabled'] && $disabled['pendingJobs'] === 0,
    'Disabling publication preserves identity linkage but cancels pending delivery.',
);
$database->prepare('DELETE FROM players WHERE id = :id')->execute(['id' => $playerOne]);
$assert(
    (int) $database->query(
        "SELECT COUNT(*) FROM game_center_publication_outbox WHERE player_id = '{$playerOne}'"
    )->fetchColumn() === 0,
    'Account deletion cascades encrypted binding and publication history.',
);

$database->beginTransaction();
$repository->enableInCurrentTransaction($playerTwo, $teamTwo, 'G:game-player-two');
$achievements = new AchievementService(
    $database,
    new CoinWalletRepository($database),
    $repository,
);
$achievements->unlockBuyPetInTransaction($playerTwo);
$achievements->unlockBuyPetInTransaction($playerTwo);
$database->commit();
$playerTwoAchievementJob = $database->query(
    "SELECT * FROM game_center_publication_outbox WHERE player_id = '{$playerTwo}' "
    . "AND publication_kind = 'achievement'"
)->fetch();
$assert(
    is_array($playerTwoAchievementJob)
        && (int) $playerTwoAchievementJob['desired_revision'] === 1
        && (int) $database->query(
            "SELECT COUNT(*) FROM player_achievements WHERE player_id = '{$playerTwo}'"
        )->fetchColumn() === 1,
    'Only the first authoritative achievement unlock creates an Apple desired state.',
);
$permanentFailureClient = new class implements GameCenterSubmissionClient {
    public function submitLeaderboard(
        string $scopedPlayerId,
        int $score,
        bool $preReleased,
    ): string {
        throw new RuntimeException('Unexpected leaderboard submission.');
    }

    public function submitAchievement(
        string $scopedPlayerId,
        string $achievementId,
        bool $preReleased,
    ): string {
        throw new GameCenterAppleApiException(
            'Apple Game Center submission failed.',
            false,
            403,
            'FORBIDDEN_ERROR',
            'Forbidden',
            'The achievement component is not available for this app version',
            '/data/attributes/vendorIdentifier',
        );
    }
};
$heldResult = (new GameCenterOutboxWorker(
    $repository,
    $permanentFailureClient,
))->run(1);
$heldRow = $database->query(
    "SELECT * FROM game_center_publication_outbox WHERE player_id = '{$playerTwo}' "
    . "AND publication_kind = 'achievement'"
)->fetch();
$assert(
    $heldResult === ['claimed' => 1, 'delivered' => 0, 'superseded' => 0, 'failed' => 1]
        && is_array($heldRow)
        && $heldRow['state'] === 'held'
        && (int) $heldRow['last_http_status'] === 403
        && $heldRow['last_error_code'] === 'FORBIDDEN_ERROR'
        && str_contains(
            (string) $heldRow['last_error'],
            'The achievement component is not available for this app version',
        )
        && str_contains(
            (string) $heldRow['last_error'],
            '/data/attributes/vendorIdentifier',
        )
        && $repository->status($playerTwo)['heldJobs'] === 1,
    'Permanent Apple failures enter operator hold with bounded sanitized diagnostics.',
);
$database->beginTransaction();
$repository->enqueueAchievementInCurrentTransaction($playerTwo, 'buy_a_pet');
$database->commit();
$heldAchievementId = is_array($heldRow) ? (string) $heldRow['id'] : '';
$assert(
    $database->query(
        "SELECT state FROM game_center_publication_outbox WHERE player_id = '{$playerTwo}' "
        . "AND publication_kind = 'achievement'"
    )->fetchColumn() === 'held',
    'Ordinary gameplay cannot revive a held job.',
);
$heldLeaderboardId = '44444444-4444-4444-8444-444444444444';
$database->exec(
    "INSERT INTO game_center_publication_outbox "
    . '(id, player_id, publication_kind, vendor_identifier, pre_released, desired_value, '
    . 'desired_revision, state, attempt_count, available_at, last_http_status, '
    . 'last_error_code, last_error, created_at, updated_at) VALUES '
    . "('{$heldLeaderboardId}', '{$playerTwo}', 'leaderboard', '"
    . GameCenterCatalog::LEADERBOARD_ARCADE_VERIFIED
    . "', 1, 77, 1, 'held', 1, CURRENT_TIMESTAMP, 409, "
    . "'ENTITY_ERROR.ATTRIBUTE.TYPE', 'Apple rejected a numeric score.', "
    . 'CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
);
$heldDiagnostics = $repository->heldDiagnostics();
$diagnosticIds = array_column($heldDiagnostics, 'id');
$assert(
    count($heldDiagnostics) === 2
        && in_array($heldAchievementId, $diagnosticIds, true)
        && in_array($heldLeaderboardId, $diagnosticIds, true)
        && !array_key_exists('playerId', $heldDiagnostics[0])
        && !array_key_exists('scopedPlayerId', $heldDiagnostics[0]),
    'Held-job inspection exposes exact operator IDs and sanitized errors without player identities.',
);
$heldAchievementRevision = (int) ($heldRow['desired_revision'] ?? 0);
$assert(
    $repository->requeueHeldById($heldAchievementId)
        && !$repository->requeueHeldById($heldAchievementId)
        && $database->query(
            "SELECT state = 'pending' AND attempt_count = 0 "
            . "AND desired_revision = {$heldAchievementRevision} + 1 "
            . 'AND last_http_status IS NULL AND last_error_code IS NULL AND last_error IS NULL '
            . "FROM game_center_publication_outbox WHERE id = '{$heldAchievementId}'"
        )->fetchColumn() == 1
        && $database->query(
            "SELECT state FROM game_center_publication_outbox WHERE id = '{$heldLeaderboardId}'"
        )->fetchColumn() === 'held',
    'Exact held-job recovery resets only the selected row and cannot revive it twice.',
);
$wrongLaneRepository = new GameCenterPublicationRepository(
    $database,
    str_repeat('publication-secret-', 3),
    false,
);
$invalidHeldIdRejected = false;
try {
    $repository->requeueHeldById('not-an-outbox-id');
} catch (InvalidArgumentException) {
    $invalidHeldIdRejected = true;
}
$assert(
    $invalidHeldIdRejected
        && !$wrongLaneRepository->requeueHeldById($heldLeaderboardId)
        && $repository->requeueHeldById($heldLeaderboardId)
        && $database->query(
            "SELECT state FROM game_center_publication_outbox WHERE id = '{$heldAchievementId}'"
        )->fetchColumn() === 'pending',
    'Held recovery validates UUIDs and remains isolated to the configured Apple lane.',
);

$reviewEntryId = '33333333-3333-4333-8333-333333333333';
$database->exec(
    "INSERT INTO leaderboard_entries "
    . "(id, player_id, mode, score, verification_status, godlike_count) VALUES "
    . "('{$reviewEntryId}', '{$playerTwo}', 'normal', 120001, 'review', 1)"
);
$database->exec(
    "INSERT INTO completed_runs "
    . "(run_id, player_id, leaderboard_entry_id, economy_generation, mode, score, "
    . "verification_status, coin_status, duration_ms, credited_play_ms, server_elapsed_ms) VALUES "
    . "('reviewed-run', '{$playerTwo}', '{$reviewEntryId}', 0, 'normal', 120001, 'review', 'withheld', "
    . '300000, 300000, 300000)'
);
$approval = (new LeaderboardModerationService(
    $database,
    $repository,
    $achievements,
))->transition(
    $reviewEntryId,
    'approve',
    'game-center-test',
    'The protocol proof was approved.',
    true,
);
$assert(
    $approval['applied'] === true
        && $approval['toStatus'] === 'verified'
        && (int) $database->query(
        "SELECT COUNT(*) FROM player_achievements WHERE player_id = '{$playerTwo}'"
    )->fetchColumn() === 5
        && (int) $database->query(
            "SELECT COUNT(*) FROM game_center_publication_outbox "
            . "WHERE player_id = '{$playerTwo}' AND publication_kind = 'achievement'"
    )->fetchColumn() === 5,
    'Approving a reviewed run immediately unlocks and enqueues every newly eligible run achievement.',
);

$fixture = __DIR__ . '/fixtures/apple-jws';
$requests = [];
$config = new Config(
    databaseHost: 'localhost',
    databasePort: 3306,
    databaseName: 'test',
    databaseUser: 'test',
    databasePassword: 'test',
    googleClientId: 'test.apps.googleusercontent.com',
    seasonId: 'season-1',
    seasonName: 'Season 1',
    gameCenterApiIssuerId: '11111111-2222-4333-8444-555555555555',
    gameCenterApiKeyId: 'TESTKEY123',
    gameCenterApiPrivateKeyPath: $fixture . '/leaf-key.pem',
    gameCenterPlayerIdEncryptionKey: str_repeat('g', 32),
    gameCenterPreReleased: true,
);
$transport = static function (
    string $url,
    array $headers,
    string $body,
) use (&$requests): array {
    $requests[] = compact('url', 'headers', 'body');
    return [
        'status' => 201,
        'headers' => ['x-rate-limit' => 'user-hour-lim:3600;'],
        'body' => '{"data":{"type":"submission","id":"apple-submission-id"}}',
    ];
};
$apple = new AppStoreConnectGameCenterClient($config, $transport);
$assert(
    $apple->submitLeaderboard('G:test', 123_456, true) === 'apple-submission-id'
        && $apple->submitAchievement('G:test', 'complete_arcade', false)
            === 'apple-submission-id',
    'The App Store Connect client accepts only server-owned score and achievement operations.',
);
$leaderboardRequest = $requests[0];
$leaderboardBody = json_decode($leaderboardRequest['body'], true, 16, JSON_THROW_ON_ERROR);
$achievementBody = json_decode($requests[1]['body'], true, 16, JSON_THROW_ON_ERROR);
$assert(
    $leaderboardRequest['url']
        === 'https://api.appstoreconnect.apple.com/v1/gameCenterLeaderboardEntrySubmissions'
        && $leaderboardBody['data']['type'] === 'gameCenterLeaderboardEntrySubmissions'
        && $leaderboardBody['data']['attributes'] === [
            'bundleId' => 'com.otcsoftware.pimpopom',
            'vendorIdentifier' => GameCenterCatalog::LEADERBOARD_ARCADE_VERIFIED,
            'scopedPlayerId' => 'G:test',
            'score' => '123456',
            'preReleased' => true,
        ],
    'Leaderboard requests use Apple JSON:API and encode the score as a decimal JSON string.',
);
$assert(
    $achievementBody['data']['attributes']['vendorIdentifier']
        === 'com.otcsoftware.pimpopom.achievement.complete_arcade'
        && $achievementBody['data']['attributes']['percentageAchieved'] === 100
        && $achievementBody['data']['attributes']['preReleased'] === false,
    'Achievement requests map the server catalog to an unlocked 100 percent submission.',
);
$authorization = array_values(array_filter(
    $leaderboardRequest['headers'],
    static fn (string $header): bool => str_starts_with($header, 'Authorization: Bearer '),
))[0] ?? '';
$jwt = substr($authorization, strlen('Authorization: Bearer '));
$jwtParts = explode('.', $jwt);
$jwtHeader = count($jwtParts) === 3
    ? json_decode($decode($jwtParts[0]), true, 8, JSON_THROW_ON_ERROR)
    : [];
$jwtPayload = count($jwtParts) === 3
    ? json_decode($decode($jwtParts[1]), true, 8, JSON_THROW_ON_ERROR)
    : [];
$assert(
    count($jwtParts) === 3
        && $jwtHeader === ['alg' => 'ES256', 'kid' => 'TESTKEY123', 'typ' => 'JWT']
        && ($jwtPayload['iss'] ?? null) === '11111111-2222-4333-8444-555555555555'
        && ($jwtPayload['aud'] ?? null) === 'appstoreconnect-v1'
        && !array_key_exists('bid', $jwtPayload),
    'Game Center uses a short-lived App Store Connect JWT, not the StoreKit server JWT.',
);
$publicKey = openssl_pkey_get_public((string) file_get_contents($fixture . '/leaf.pem'));
$assert(
    $publicKey instanceof OpenSSLAsymmetricKey
        && openssl_verify(
            $jwtParts[0] . '.' . $jwtParts[1],
            $rawSignatureToDer($decode($jwtParts[2])),
            $publicKey,
            OPENSSL_ALGO_SHA256,
        ) === 1,
    'The App Store Connect bearer token has a valid P-256 ES256 signature.',
);

$retryClient = new AppStoreConnectGameCenterClient(
    $config,
    static fn (): array => [
        'status' => 429,
        'body' => json_encode([
            'errors' => [[
                'code' => 'RATE_LIMIT_EXCEEDED',
                'title' => "Too\tMany Requests",
                'detail' => 'Player G:test sent Authorization: Bearer eyJsecret.payload.signature',
                'source' => ['pointer' => '/data/attributes/score'],
            ]],
        ], JSON_THROW_ON_ERROR),
    ],
);
$retryable = false;
try {
    $retryClient->submitLeaderboard('G:test', 1, true);
} catch (GameCenterAppleApiException $error) {
    $retryable = $error->retryable
        && $error->httpStatus === 429
        && $error->appleCode === 'RATE_LIMIT_EXCEEDED'
        && $error->appleTitle === 'Too Many Requests'
        && str_contains($error->appleDetail ?? '', '[redacted]')
        && !str_contains($error->appleDetail ?? '', 'G:test')
        && !str_contains($error->appleDetail ?? '', 'eyJsecret')
        && $error->appleSourcePointer === '/data/attributes/score'
        && !str_contains($error->operatorDiagnostic(), 'G:test');
}
$assert(
    $retryable,
    'Apple rate limits retain only bounded sanitized diagnostics for durable outbox retry.',
);

$hostileClient = new AppStoreConnectGameCenterClient(
    $config,
    static fn (): array => [
        'status' => 403,
        'body' => json_encode([
            'errors' => [[
                'code' => "FORBIDDEN_ERROR\nINJECTED",
                'title' => 'Forbidden for G:hostile-player',
                'detail' => 'Authorization: Bearer eyJsecret.payload.signature '
                    . 'owner@example.com ' . str_repeat('A', 64),
                'source' => ['pointer' => '/data/attributes/G:hostile-player'],
            ]],
        ], JSON_THROW_ON_ERROR),
    ],
);
$hostileDiagnosticWasSanitized = false;
try {
    $hostileClient->submitAchievement('G:hostile-player', 'complete_arcade', true);
} catch (GameCenterAppleApiException $error) {
    $operatorDiagnostic = $error->operatorDiagnostic();
    $hostileDiagnosticWasSanitized = $error->appleCode === null
        && $error->appleSourcePointer === null
        && str_contains($operatorDiagnostic, '[redacted]')
        && !str_contains($operatorDiagnostic, 'G:hostile-player')
        && !str_contains($operatorDiagnostic, 'eyJsecret')
        && !str_contains($operatorDiagnostic, 'owner@example.com')
        && !str_contains($operatorDiagnostic, str_repeat('A', 64))
        && strlen($operatorDiagnostic) <= 500;
}
$assert(
    $hostileDiagnosticWasSanitized,
    'Reflected player IDs, credentials, opaque values, and malformed metadata never enter diagnostics.',
);

fwrite(
    STDOUT,
    "Game Center publication checks passed ({$assertions} assertions).\n",
);
