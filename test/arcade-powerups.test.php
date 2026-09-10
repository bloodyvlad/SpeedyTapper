<?php

declare(strict_types=1);

use SpeedyTapper\AchievementService;
use SpeedyTapper\ApiException;
use SpeedyTapper\ArcadePowerUpReplay;
use SpeedyTapper\CoinProgression;
use SpeedyTapper\CoinWalletRepository;
use SpeedyTapper\LeaderboardRepository;
use SpeedyTapper\MigrationRunner;
use SpeedyTapper\RunAttemptService;
use SpeedyTapper\RunProof;
use SpeedyTapper\RunProofValidator;
use SpeedyTapper\RunSubmissionService;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$rejects = static function (callable $call, string $message, int $status = 400) use ($assert): void {
    try {
        $call();
    } catch (ApiException $error) {
        $assert($error->status === $status, $message . ' (status)');
        return;
    }
    $assert(false, $message);
};
$proof = static function (array $events, bool $v4 = true): RunProof {
    return RunProof::fromArray([
        'runId' => 'b49b17f9-7e8d-4bb5-9e85-7da62b72b514', 'mode' => 'normal',
        'buildId' => '20260910-1',
        'ruleset' => $v4 ? RunProof::POWER_UP_RULESET : RunProof::RULESET,
        'proofVersion' => $v4 ? RunProof::POWER_UP_PROOF_VERSION : RunProof::PROOF_VERSION,
        'events' => $events,
    ]);
};
foreach ([['reaction-proof-v3', 2], ['reaction-proof-v4', 3]] as [$ruleset, $version]) {
    $assert(RunProof::ticketContract('20260910-1', $ruleset, $version)
        === ['ruleset' => $ruleset, 'proofVersion' => $version], 'Exact requested contract is returned.');
}
$assert(RunProof::ticketContract('20991231-999') === ['ruleset' => 'reaction-proof-v3', 'proofVersion' => 2],
    'Higher builds without an explicit contract still receive unchanged v3.');
$assert(RunProof::requestedContract(['mode' => 'normal', 'buildId' => '20260910-1'])
    === ['ruleset' => 'reaction-proof-v3', 'proofVersion' => 2], 'Absent HTTP capabilities retain v3.');
foreach ([['ruleset' => 'reaction-proof-v4'], ['proofVersion' => 3], ['ruleset' => null], ['proofVersion' => null]] as $partial) {
    $rejects(fn () => RunProof::requestedContract($partial), 'Partial HTTP capability fields are rejected, including null.');
}
$assert(RunProof::requestedContract(['ruleset' => null, 'proofVersion' => null])
    === ['ruleset' => null, 'proofVersion' => null], 'Explicit nulls are not silently replaced with v3 defaults.');
foreach ([['reaction-proof-v3', 3], ['reaction-proof-v4', 2], ['reaction-proof-v4', '3'],
    ['reaction-proof-v5', 3], [null, null], ['reaction-proof-v4', null]] as [$ruleset, $version]) {
    $assert(RunProof::ticketContract('20260910-1', $ruleset, $version) === null,
        'Unknown, partial, mistyped and mixed contracts are not admitted.');
}
$newTuples = [[7, 12_000, 1, 0, 1, 3_000], [8, 12_500, 12_502, 1, 1], [9, 15_000, 1], [10, 20_000]];
$assert($proof($newTuples)->events === $newTuples, 'V4 retains exact new integer tuple shapes.');
foreach ($newTuples as $event) {
    $rejects(fn () => $proof([$event], false), 'V3 refuses every v4 opcode.');
}
foreach ([[7, 12_000, 1, 0, 1], [8, 12_500, 12_502, 1, 1, 2], [9, 15_000, 1, 1],
    [10, 20_000, 1], [8, 12_500, 12_502, '1', 1], [11, 12_000]] as $event) {
    $rejects(fn () => $proof([$event]), 'V4 rejects unknown opcodes, lengths and non-integers.');
}
$minimal = [[2, 100, 100, 0, 0], [2, 1_600, 1_600, 0, 0], [2, 3_100, 3_100, 0, 0], [5, 3_100, 3_100]];
$v3 = $proof($minimal, false);
$v4 = $proof($minimal);
$v3Score = (new RunProofValidator())->validate($v3);
$v4Score = (new RunProofValidator())->validate($v4);
$assert($v3Score->score === 0 && $v4Score->score === 0 && $v4Score->misses === 3
    && $v4Score->survivalMs === 3_100, 'No-pickup v4 replays unchanged Arcade timing and finish.');
$assert(!hash_equals($v3->traceHash(), $v4->traceHash()), 'V4 timing semantics have a separate trace namespace.');
$assert(hash_equals($v4->proofHash(), $proof($minimal)->proofHash()), 'V4 canonical hashes remain deterministic.');
$assert(CoinProgression::accrue(59_000, $v4Score->survivalMs)->coinsEarned === 1,
    'Existing accounting still uses real verified survival milliseconds.');

// Shared with Swift PimPoPomCore commit 2982e6d7cec0fc7884c30b19f90fcb3af5de78c2.
// Formatting differs; every event and expected value is identical.
$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/arcade-v4-powerups.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($fixtures as $fixture) {
    $score = (new RunProofValidator())->validate($proof($fixture['events']));
    $expected = $fixture['expected'];
    foreach (['score' => 'score', 'hits' => 'hits', 'misses' => 'misses', 'dodges' => 'dodges',
        'survivalMs' => 'durationMs', 'fastestReactionMs' => 'fastestReactionMs',
        'maxMultiplier' => 'maximumMultiplierUsed'] as $property => $key) {
        $assert($score->{$property} === $expected[$key], $fixture['name'] . ' matches Swift ' . $key . '.');
    }
    $assert($score->averageReactionMs === (int) round($expected['reactionTotalMs'] / $expected['hits']),
        $fixture['name'] . ' matches Swift reaction totals.');
    $rejects(fn () => $proof($fixture['events'], false), 'Complete v4 trace cannot be labeled as v3.');
}
$mutants = [];
$events = $fixtures[0]['events'];
$events[54][4] = 0;
$mutants[] = $events;
$events = $fixtures[0]['events'];
array_splice($events, 54, 1);
$mutants[] = $events;
$events = $fixtures[0]['events'];
array_splice($events, 55, 0, [$events[54]]);
$mutants[] = $events;
$events = $fixtures[0]['events'];
$events[53][2] = 2;
$mutants[] = $events;
$events = $fixtures[1]['events'];
$events[127][1] = 32_999;
$mutants[] = $events;
$events = $fixtures[1]['events'];
$events[39][1] = 15_000;
$events[39][2] = 15_000;
$mutants[] = $events;
$events = $fixtures[1]['events'];
$events[38][3] = 2;
$mutants[] = $events;
foreach ($mutants as $events) {
    $rejects(fn () => (new RunProofValidator())->validate($proof($events)),
        'Tampered complete pickup proof is rejected.');
}
$events = $fixtures[1]['events'];
$claim = array_splice($events, 39, 1)[0];
$claim[1] = $claim[2] = 12_300;
array_splice($events, 41, 0, [$claim]);
$events[42][1] = $events[42][2] = 13_450;
array_splice($events, 42, 0, [[4, 13_200, 2]]);
try {
    (new RunProofValidator())->validate($proof($events));
    $assert(false, 'Clock collected during an active target cannot extend its old deadline.');
} catch (ApiException $error) {
    $assert(str_contains($error->getMessage(), 'claimed correct tap'),
        'Clock leaves active target response immutable even when the new rate would allow the contact.');
}

$heart = new ArcadePowerUpReplay();
$heart->activate([7, 12_000, 1, 0, 1, 3_000], 2, 0, [], 0, 0);
$assert($heart->occupiedCell() === 1, 'Live pickup reserves its own board cell.');
$assert($heart->claim([8, 12_200, 12_202, 1, 1], 2, 0, 1) === 3 && $heart->restoredLives() === 1,
    'A valid heart restores one missing life and records the exact restoration.');
$assert($heart->occupiedCell() === null, 'Claim consumes the pickup.');
$rejects(fn () => $heart->claim([8, 12_201, 12_203, 1, 1], 2, 0, 2), 'Duplicate claim cannot restore again.');
$heart->activate([7, 24_000, 2, 0, 2, 3_000], 2, 0, [], 0, 3);
$assert($heart->claim([8, 24_100, 24_102, 2, 2], 3, 0, 4) === 3 && $heart->restoredLives() === 1,
    'Full-health heart is consumed without inflating lives or restoration count.');
$heart->loseLife(25_000, 1_500);
$rejects(fn () => $heart->activate([7, 38_498, 3, 0, 1, 3_000], 2, 0, [], 0, 5),
    'Miss resets the unscaled pickup cadence after recovery.');
$heart->activate([7, 38_500, 3, 0, 1, 3_000], 2, 0, [], 0, 6);
$rejects(fn () => $heart->claim([8, 38_499, 38_600, 3, 1], 1, 0, 7), 'Contact cannot predate appearance.');
$rejects(fn () => $heart->claim([8, 38_600, 38_602, 3, 2], 1, 0, 8), 'Wrong-cell claim is rejected.');
$rejects(fn () => $heart->claim([8, 38_600, 38_602, 9, 1], 1, 0, 9), 'Wrong-ID claim is rejected.');
$rejects(fn () => $heart->claim([8, 38_600, 38_602, 3, 1], 0, 0, 10), 'Pickup cannot revive an eliminated player.');
$rejects(fn () => $heart->claim([8, 41_500, 41_502, 3, 1], 1, 0, 11), 'Contact at expiry is not a pickup.');
$heart->assertBeforeEvent(0, 41_510, 41_510, 12);
$assert($heart->occupiedCell() === 1, 'Two-frame expiry drain retains cell ownership without benefit.');
$assert($heart->claim([8, 41_499, 41_520, 3, 1], 1, 0, 13) === 2,
    'Queued original contact before expiry may be handled later, before expiry commits.');

$clock = new ArcadePowerUpReplay();
$clock->activate([7, 12_000, 1, 1, 1, 3_000], 2, 0, [], 0, 0);
$assert($clock->claim([8, 14_999, 15_002, 1, 1], 2, 0, 1) === 2, 'Clock does not restore a life.');
$assert((new ArcadePowerUpReplay())->scaleInterval(1_000, 15_001) === 1_000, 'Unclaimed clock has no effect.');
$assert($clock->scaleInterval(1_000, 15_002) === 1_429, 'Immediate 0.70 rate uses inverse ceiling, not +30 percent.');
$assert($clock->scaleInterval(1_000, 20_002) === 1_177, 'Rate recovers linearly to 0.85 halfway through ten seconds.');
$assert($clock->scaleInterval(1_000, 25_002) === 1_000, 'Rate is exactly normal at ten seconds.');
$assert($clock->scaleInterval(250, 15_002) === 358, 'Short quiet intervals use the same integer rounding.');
$clock->activate([7, 24_000, 2, 1, 2, 3_000], 2, 0, [], 0, 2);
$clock->claim([8, 24_000, 24_002, 2, 2], 2, 0, 3);
$assert($clock->scaleInterval(1_000, 24_002) === 1_429 && $clock->scaleInterval(1_000, 34_002) === 1_000,
    'Overlapping clock pickup refreshes one 0.70 anchor, never multiplies effects.');
$clock->loseLife(24_500, 1_500);
$assert($clock->scaleInterval(1_000, 25_000) > 1_000, 'Clock persists across nonterminal life-loss recovery.');

foreach ([[7, 11_998, 1, 0, 1, 3_000], [7, 25_001, 1, 0, 1, 3_000],
    [7, 12_000, 2, 0, 1, 3_000], [7, 12_000, 1, 2, 1, 3_000],
    [7, 12_000, 1, 0, 1, 3_001], [7, 12_000, 1, 0, 0, 3_000]] as $event) {
    $rejects(fn () => (new ArcadePowerUpReplay())->activate($event, 2, 0, [], 0, 0),
        'Spawn rejects cadence, identity, kind, lifetime and occupied target violations.');
}
$rejects(fn () => (new ArcadePowerUpReplay())->activate([7, 12_000, 1, 0, 1, 3_000], 2, 0,
    [1 => [], 2 => []], 0, 0), 'Pickup cannot overlap a decoy or consume the last target-capable space.');
$rejects(fn () => (new ArcadePowerUpReplay())->activate([7, 12_000, 1, 0, 0, 3_000], 1, null, [], 0, 0),
    'Pickups never block the 1x1 warmup board.');
$rejects(fn () => (new ArcadePowerUpReplay())->activate([7, 12_000, 1, 0, 1, 3_000], 2, 0, [], 12_100, 0),
    'Power-ups do not spawn in recovery.');
$blocked = new ArcadePowerUpReplay();
$blocked->ignoredOpportunity(12_000, 1, null, [], 0, 0);
$rejects(fn () => $blocked->activate([7, 12_248, 1, 0, 1, 3_000], 2, 0, [], 0, 1),
    'Blocked opportunity retry is 250ms, not an immediate random resample.');
$blocked->activate([7, 12_250, 1, 0, 1, 3_000], 2, 0, [], 0, 2);
$rejects(fn () => (new ArcadePowerUpReplay())->ignoredOpportunity(12_000, 2, 0, [], 0, 0),
    'No-spawn event must be justified by board state.');
$rejects(fn () => (new ArcadePowerUpReplay())->assertBeforeEvent(0, 25_001, 25_001, 0),
    'Missing opportunity cannot be omitted indefinitely.');
$rejects(fn () => $blocked->expire([9, 15_249, 1], 3), 'Early expiry is rejected.');
$rejects(fn () => $blocked->expire([9, 15_250, 2], 3), 'Wrong expiry ID is rejected.');
$rejects(fn () => $blocked->assertBeforeEvent(0, 20_251, 20_251, 3), 'Uncommitted expiry remains bounded.');
$blocked->expire([9, 15_250, 1], 3);
$assert($blocked->occupiedCell() === null && $blocked->restoredLives() === 0, 'Expiry grants no restoration.');
$rejects(fn () => $blocked->claim([8, 15_249, 15_251, 1, 1], 2, 0, 4), 'Committed expiry cannot be resurrected.');

final class ArcadePowerUpSqlitePDO extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace([' FOR UPDATE', 'UTC_TIMESTAMP(3) + INTERVAL 24 HOUR',
            'UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE', 'UTC_TIMESTAMP(3) - INTERVAL 1 DAY', 'UTC_TIMESTAMP(3)'],
            ['', "datetime('now', '+24 hour')", "datetime('now', '-1 minute')", "datetime('now', '-1 day')", 'CURRENT_TIMESTAMP'], $query);
        return parent::prepare($query, $options);
    }
}
$dsn = getenv('SPEEDYTAPPER_ARCADE_TEST_DSN') ?: '';
if ($dsn !== '') {
    if (!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=speedytapper_arcade_v4;charset=utf8mb4$/D', $dsn)) {
        throw new RuntimeException('Arcade database tests require the dedicated disposable loopback schema.');
    }
    $database = new PDO($dsn, 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $database->exec("SET time_zone = '+00:00'");
    $database->exec('CREATE TABLE players (id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY) ENGINE=InnoDB');
    $migration = file_get_contents(dirname(__DIR__) . '/server/migrations/006_verified_runs_and_moderation.sql');
    $created = false;
    foreach (MigrationRunner::splitStatements($migration) as $statement) {
        if (str_contains($statement, 'CREATE TABLE IF NOT EXISTS run_attempts (')) {
            $database->exec($statement);
            $created = true;
        }
    }
    $assert($created, 'MariaDB test uses the actual unchanged migration 006 attempt schema.');
} else {
    $database = new ArcadePowerUpSqlitePDO();
    $database->exec('CREATE TABLE players (id TEXT PRIMARY KEY); CREATE TABLE run_attempts ('
        . 'run_id TEXT PRIMARY KEY, session_binding_hash BLOB, player_id TEXT, mode TEXT, build_id TEXT, '
        . 'ruleset_id TEXT, proof_version INTEGER, status TEXT, started_at TEXT, expires_at TEXT)');
}
$player = '09678a3d-97e9-466c-a9b6-2db377953ac2';
$database->prepare('INSERT INTO players (id) VALUES (?)')->execute([$player]);
$attempts = new RunAttemptService($database);
$oldTicket = $attempts->start($player, str_repeat('a', 32), 'normal', '20260910-1');
$newTicket = $attempts->start($player, str_repeat('a', 32), 'normal', '20260910-1', 'reaction-proof-v4', 3);
$assert($oldTicket['ruleset'] === 'reaction-proof-v3' && $oldTicket['proofVersion'] === 2,
    'Legacy start request retains v3/proof2.');
$assert($newTicket['ruleset'] === 'reaction-proof-v4' && $newTicket['proofVersion'] === 3,
    'Explicit v4 is bound into returned ticket.');
$rows = [];
foreach ([$oldTicket['runId'], $newTicket['runId']] as $runId) {
    $select = $database->prepare('SELECT * FROM run_attempts WHERE run_id = ?');
    $select->execute([$runId]);
    $rows[] = $select->fetch();
}
$assert($rows[0]['status'] === 'abandoned' && $rows[1]['status'] === 'issued'
    && $rows[1]['ruleset_id'] === 'reaction-proof-v4' && (int) $rows[1]['proof_version'] === 3,
    'V4 preserves one issued attempt and immutable stored contract.');
foreach ([['reaction-proof-v4', 2], [null, null], ['reaction-proof-v4', '3']] as [$ruleset, $version]) {
    $rejects(fn () => $attempts->start($player, str_repeat('a', 32), 'normal', '20260910-1', $ruleset, $version),
        'Malformed requested contract is rejected before abandoning current run.');
}
$assert((int) $database->query("SELECT COUNT(*) FROM run_attempts WHERE status = 'issued'")->fetchColumn() === 1,
    'Failed capability request leaves existing attempt untouched.');
$wallets = new CoinWalletRepository($database);
$submissions = new RunSubmissionService($database, new LeaderboardRepository($database, 'season-1', 'Season 1'),
    new RunProofValidator(), new AchievementService($database, $wallets), $wallets);
$ownership = new ReflectionMethod(RunSubmissionService::class, 'assertAttemptOwnership');
$ownership->invoke($submissions, $rows[1], $player, str_repeat('a', 32), $v4);
$assert(true, 'New proof matches exact issued v4 tuple.');
$rejects(fn () => $ownership->invoke($submissions, $rows[1], $player, str_repeat('a', 32), $v3),
    'V3 proof cannot consume a v4 ticket.', 409);
$handled = new ReflectionMethod(RunSubmissionService::class, 'proofHandledMs');
$assert($handled->invoke($submissions, $proof([[8, 12_000, 14_000, 1, 1]])) === 14_000,
    'Server wall-clock checks include pickup handling time, not scaled time or contact alone.');
$appSource = file_get_contents(dirname(__DIR__) . '/server/src/App.php');
$assert(str_contains($appSource, 'RunProof::requestedContract($body)'), 'HTTP start uses the tested exact capability parser.');

fwrite(STDOUT, "Arcade power-up checks passed ({$assertions} assertions; " . ($dsn === '' ? 'SQLite' : 'MariaDB') . ").\n");
