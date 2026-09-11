<?php
declare(strict_types=1);

use SpeedyTapper\AchievementService;
use SpeedyTapper\CoinWalletRepository;
use SpeedyTapper\GameCenterPublicationRepository;
use SpeedyTapper\LeaderboardRepository;
use SpeedyTapper\MigrationRunner;
use SpeedyTapper\RunAttemptService;
use SpeedyTapper\RunProof;
use SpeedyTapper\RunProofValidator;
use SpeedyTapper\RunSubmissionService;
use SpeedyTapper\Uuid;

$root = dirname(__DIR__);
require $root . '/server/autoload.php';
$dsn = getenv('SPEEDYTAPPER_ARCADE_V5_DSN') ?: '';
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=speedytapper_arcade_v5;charset=utf8mb4$/D', $dsn)) {
    throw new RuntimeException('Only the disposable loopback test database is allowed.');
}
$db = new PDO($dsn, 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$db->exec("SET time_zone = '+00:00'");
if ((int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('Disposable database must be empty before test migrations.');
}
$applied = (new MigrationRunner($db, $root . '/server/migrations'))->run();
if (count($applied) !== 24) throw new RuntimeException('Expected exact local schema 001–024.');
$assertions = 0;
$assert = static function (bool $ok, string $description) use (&$assertions): void {
    $assertions++;
    if (!$ok) throw new RuntimeException($description);
};
$row = static function (string $sql, array $parameters) use ($db): array {
    $query = $db->prepare($sql);
    $query->execute($parameters);
    $result = $query->fetch();
    if (!is_array($result)) throw new RuntimeException('Expected persisted test row.');
    return $result;
};
$leaderboard = new LeaderboardRepository($db, 'arcade-v5-local-check', 'Disposable Arcade contract check');
$leaderboard->ensureSeason();
$wallets = new CoinWalletRepository($db);
$publication = new GameCenterPublicationRepository($db, str_repeat('disposable-test-key-', 3), true);
$achievements = new AchievementService($db, $wallets, $publication);
$service = new RunSubmissionService($db, $leaderboard, new RunProofValidator(), $achievements, $wallets, $publication);
$attempts = new RunAttemptService($db);
$fixtures = [];
foreach (['reaction-proof-v4' => 'arcade-v4-powerups.json', 'reaction-proof-v5' => 'arcade-v5-powerups.json'] as $ruleset => $file) {
    $goldens = json_decode(file_get_contents(__DIR__ . '/fixtures/' . $file), true, 512, JSON_THROW_ON_ERROR);
    foreach ($goldens as $fixture) {
        $fixture['ruleset'] = $ruleset;
        $fixtures[] = $fixture;
    }
}
foreach ([['reaction-proof-v5', 3], ['reaction-proof-v4', 3], ['reaction-proof-v3', 2]] as [$ruleset, $version]) {
    $fixtures[] = ['name' => 'eligible-control-' . $ruleset, 'ruleset' => $ruleset, 'proofVersion' => $version,
        'carryMs' => 59000,
        'events' => [[2, 100, 100, 0, 0], [2, 1600, 1600, 0, 0], [2, 3100, 3100, 0, 0], [5, 3100, 3100]],
        'expected' => ['score' => 0, 'misses' => 3, 'durationMs' => 3100, 'maximumMultiplierUsed' => 1]];
}
foreach ($fixtures as $index => $fixture) {
    $ruleset = $fixture['ruleset'] ?? 'reaction-proof-v4';
    $version = $fixture['proofVersion'] ?? 3;
    $carryMs = $fixture['carryMs'] ?? 0;
    $playerId = Uuid::v4();
    $binding = hash('sha256', 'disposable-versioned-session-' . $index, true);
    $db->prepare('INSERT INTO players (id, nickname, nickname_confirmed, coin_time_remainder_ms) VALUES (?, ?, 1, ?)')
        ->execute([$playerId, 'ArcadeFixture' . $index, $carryMs]);
    $ticket = $version === 2
        ? $attempts->start($playerId, $binding, 'normal', '20260910-1')
        : $attempts->start($playerId, $binding, 'normal', '20260910-1', $ruleset, $version);
    $expected = $fixture['expected'];
    $ageMs = (int) $expected['durationMs'] + 100;
    $db->prepare('UPDATE run_attempts SET started_at = TIMESTAMPADD(MICROSECOND, ?, UTC_TIMESTAMP(3)) WHERE run_id = ?')
        ->execute([-$ageMs * 1000, $ticket['runId']]);
    $proof = RunProof::fromArray(['runId' => $ticket['runId'], 'mode' => 'normal', 'buildId' => '20260910-1',
        'ruleset' => $ruleset, 'proofVersion' => $version, 'events' => $fixture['events']]);

    $assert($ticket['ruleset'] === $ruleset && $ticket['proofVersion'] === $version, 'Attempt echoes exact requested contract.');
    try {
        $attempts->start($playerId, $binding, 'normal', '20260911-1', 'reaction-proof-v5', 4);
        $assert(false, 'Invalid admission must not abandon the valid attempt.');
    } catch (\SpeedyTapper\ApiException $error) {
        $assert($error->status === 400, 'Mixed v5/proof4 is rejected.');
    }
    $mismatched = RunProof::fromArray([...json_decode($proof->canonicalJson(), true, 512, JSON_THROW_ON_ERROR),
        'ruleset' => $ruleset === 'reaction-proof-v5' ? 'reaction-proof-v4' : 'reaction-proof-v5',
        'proofVersion' => 3]);
    try {
        $service->submit($playerId, $binding, $mismatched);
        $assert(false, 'Cross-contract finish must fail before replay or credit.');
    } catch (\SpeedyTapper\ApiException $error) {
        $assert($error->status === 409, 'A proof cannot consume a different ruleset ticket.');
    }
    $beforeSubmit = $row('SELECT status FROM run_attempts WHERE run_id = ?', [$ticket['runId']]);
    $assert($beforeSubmit['status'] === 'issued', 'Rejected capability/finish preserves issued attempt.');
    $payload = $service->submit($playerId, $binding, $proof);
    $completed = $row('SELECT * FROM completed_runs WHERE run_id = ?', [$ticket['runId']]);
    $board = $row('SELECT * FROM leaderboard_entries WHERE id = ?', [$ticket['runId']]);
    $storedProof = $row('SELECT * FROM run_proofs WHERE run_id = ?', [$ticket['runId']]);
    $attempt = $row('SELECT * FROM run_attempts WHERE run_id = ?', [$ticket['runId']]);
    $ledger = $row('SELECT * FROM coin_ledger WHERE run_id = ?', [$ticket['runId']]);
    $player = $row('SELECT * FROM players WHERE id = ?', [$playerId]);
    $assert($payload['duplicate'] === false && $attempt['status'] === 'completed', 'First submit completes once.');
    foreach (['score' => 'score', 'miss_count' => 'misses', 'duration_ms' => 'durationMs', 'max_multiplier' => 'maximumMultiplierUsed'] as $field => $expectedField) {
        $assert((int) $completed[$field] === $expected[$expectedField], 'Persisted complete result matches Swift ' . $field);
    }
    $assert($completed['ruleset_id'] === $ruleset && (int) $completed['proof_version'] === $version,
        'Completed result retains exact requested contract.');
    $assert((int) $board['score'] === $expected['score'] && $board['ruleset_id'] === $ruleset
        && (int) $board['proof_version'] === $version, 'Leaderboard result retains exact contract/score.');
    $assert((int) $storedProof['proof_version'] === $version && (int) $storedProof['event_count'] === count($fixture['events'])
        && json_decode($storedProof['proof_json'], true, 512, JSON_THROW_ON_ERROR)['events'] === $fixture['events'],
        'Stored proof retains every unmodified Swift event.');
    $assert($payload['verifiedResult']['misses'] === $expected['misses'] && $payload['verifiedResult']['score'] === $expected['score'],
        'Response retains cumulative misses and exact score.');
    $expectedEligible = $completed['verification_status'] === 'verified';
    $assert($carryMs === 0 || $expectedEligible, 'Unmodified eligible control is verified.');
    $expectedCredit = $expectedEligible ? $expected['durationMs'] : 0;
    $assert((int) $player['total_play_ms'] === $expectedCredit
        && (int) $ledger['play_ms_delta'] === $expectedCredit, 'Progression uses eligible raw wall time.');
    $assert((int) $player['earned_coins'] === ($expectedEligible ? intdiv($carryMs + $expected['durationMs'], 60000) : 0)
        && (int) $player['purchased_coins'] === 0, 'Only expected eligible earned coins change.');
    $assert((int) $player['coin_time_remainder_ms'] === ($expectedEligible ? ($carryMs + $expected['durationMs']) % 60000 : $carryMs),
        'Eligible raw wall time preserves exact cumulative remainder.');
    $achievementRows = $db->prepare('SELECT achievement_key FROM player_achievements WHERE player_id = ?');
    $achievementRows->execute([$playerId]);
    $achievementKeys = $achievementRows->fetchAll(PDO::FETCH_COLUMN);
    $assert(!$expectedEligible || in_array('complete_arcade', $achievementKeys, true), 'Verified cumulative-miss finish unlocks Arcade completion.');
    $retry = $service->submit($playerId, $binding, $proof);
    $assert($retry['duplicate'] === true && $retry['verifiedResult'] === null
        && $retry['verificationStatus'] === $payload['verificationStatus'],
        'Exact retry retains existing nullable-result response contract.');
    $retriedCompleted = $row('SELECT * FROM completed_runs WHERE run_id = ?', [$ticket['runId']]);
    $assert($retriedCompleted === $completed, 'Exact retry leaves every persisted result field unchanged.');
    $afterRetry = $row('SELECT * FROM players WHERE id = ?', [$playerId]);
    $assert($player === $afterRetry, 'Exact retry does not duplicate wallet/progression writes.');
    $countRows = $row('SELECT COUNT(*) AS total FROM coin_ledger WHERE run_id = ?', [$ticket['runId']]);
    $assert((int) $countRows['total'] === 1, 'Only one immutable coin ledger row exists after retry.');
    fwrite(STDOUT, json_encode(['fixture' => $fixture['name'], 'score' => (int) $completed['score'],
        'misses' => (int) $completed['miss_count'], 'durationMs' => (int) $completed['duration_ms'],
        'verificationStatus' => $completed['verification_status'], 'riskReasons' => $completed['risk_reasons'],
        'coinStatus' => $completed['coin_status'], 'earnedCoins' => (int) $player['earned_coins'],
        'creditedPlayMs' => (int) $player['total_play_ms'], 'achievements' => $achievementKeys,
        'exactRetry' => $retry['duplicate']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
}
fwrite(STDOUT, 'Full persisted Arcade contract checks passed (' . $assertions . " assertions; actual migrations 001–024; MariaDB).\n");
