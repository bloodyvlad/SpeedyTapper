<?php
declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\ArcadePowerUpReplay;
use SpeedyTapper\RunProof;
use SpeedyTapper\RunProofValidator;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;
$assert = static function (bool $ok, string $message) use (&$assertions): void {
    $assertions++;
    if (!$ok) throw new RuntimeException($message);
};
$rejects = static function (callable $callback, string $contains) use ($assert): void {
    try { $callback(); }
    catch (ApiException $error) {
        $assert($error->status === 400 && str_contains($error->getMessage(), $contains), $contains);
        return;
    }
    $assert(false, 'Expected rejection: ' . $contains);
};
$proof = static fn (array $events, string $ruleset = 'reaction-proof-v5', int $version = 3): RunProof => RunProof::fromArray([
    'runId' => 'de70b026-c27a-4f1e-9820-8ce2177d34d3', 'mode' => 'normal', 'buildId' => '20260911-1',
    'ruleset' => $ruleset, 'proofVersion' => $version, 'events' => $events,
]);
$minimal = [[2, 100, 100, 0, 0], [2, 1600, 1600, 0, 0], [2, 3100, 3100, 0, 0], [5, 3100, 3100]];
foreach ([['reaction-proof-v3', 2], ['reaction-proof-v4', 3], ['reaction-proof-v5', 3]] as [$ruleset, $version]) {
    $assert(RunProof::ticketContract('20260911-1', $ruleset, $version)
        === ['ruleset' => $ruleset, 'proofVersion' => $version], 'Admission retains each exact contract.');
    $score = (new RunProofValidator())->validate($proof($minimal, $ruleset, $version));
    $assert($score->misses === 3 && $score->score === 0 && $score->survivalMs === 3100, 'Unchanged baseline finish.');
}
foreach ([['reaction-proof-v5', 2], ['reaction-proof-v5', 4], ['reaction-proof-v5', '3'],
    ['reaction-proof-v5', null], ['reaction-proof-v6', 3]] as [$ruleset, $version]) {
    $assert(RunProof::ticketContract('20260911-1', $ruleset, $version) === null, 'Unsupported exact pair rejected.');
}
$assert(RunProof::ticketContract('20991231-999') === ['ruleset' => 'reaction-proof-v3', 'proofVersion' => 2],
    'Build IDs do not select new semantics.');
$assert($proof($minimal)->minimumPickupGridDimension() === 4
    && $proof($minimal, 'reaction-proof-v4')->minimumPickupGridDimension() === 2, 'Threshold comes from issued ruleset.');
$assert(!hash_equals($proof($minimal)->traceHash(), $proof($minimal, 'reaction-proof-v4')->traceHash()),
    'V5 has a distinct trace namespace despite unchanged tuple wire version.');
$tuples = [[7, 40000, 1, 0, 0, 3000], [8, 40001, 40003, 1, 0], [9, 43000, 1], [10, 12000]];
$assert($proof($tuples)->events === $tuples, 'Proof3 wire tuples are unchanged.');
foreach ([[7, 40000, 1, 0, 0], [8, 40001, 40003, 1, 0, 0], [9, 43000, 1, 2], [10, 12000, 0]] as $bad) {
    $rejects(fn () => $proof([$bad]), 'Run proof event');
}

foreach ([0, 1] as $kind) {
    $v4 = new ArcadePowerUpReplay(2);
    $v4->activate([7, 12000, 1, $kind, 1, 3000], 2, 0, [], 0, 0);
    $assert($v4->occupiedCell() === 1, 'Build27 heart/clock still spawn on2x2.');
    $v5 = new ArcadePowerUpReplay(4);
    $rejects(fn () => $v5->activate([7, 12000, 1, $kind, 1, 3000], 2, 0, [], 0, 0), 'Power-up placement');
}
$v4 = new ArcadePowerUpReplay(2);
$rejects(fn () => $v4->ignoredOpportunity(12000, 2, 0, [], 0, 0), 'could have placed');
$v5 = new ArcadePowerUpReplay(4);
for ($at = 12000; $at <= 40000; $at += 250) {
    // The old2x2 target may remain active past40s: use the visible frozen grid.
    $v5->ignoredOpportunity($at, 2, 0, [], 0, intdiv($at - 12000, 250));
}
$assert($v5->occupiedCell() === null, 'No pickup while the visible board remains2x2, including at40s.');
$v5->activate([7, 40250, 1, 0, 1, 3000], 4, 0, [], 0, 114);
$assert($v5->occupiedCell() === 1, 'First actual4x4 opportunity places without restarting cadence.');
$assert($v5->claim([8, 40300, 40302, 1, 1], 2, 0, 115) === 3, 'Heart restores a life after4x4 admission.');
$v5->activate([7, 52250, 2, 1, 2, 3000], 4, 0, [], 0, 116);
$v5->claim([8, 52300, 52302, 2, 2], 3, 0, 117);
$assert($v5->scaleInterval(1000, 52302) === 1429 && $v5->scaleInterval(1000, 62302) === 1000,
    'V5 retains exact clock arithmetic.');
$rejects(fn () => (new ArcadePowerUpReplay(4))->assertBeforeEvent(0, 25001, 25001, 0), 'opportunity is missing');
$recovering = new ArcadePowerUpReplay(4);
$rejects(fn () => $recovering->activate([7, 12000, 1, 0, 1, 3000], 4, 0, [], 12500, 0), 'scheduling window');
$full = array_fill_keys(range(1, 14), []);
$rejects(fn () => (new ArcadePowerUpReplay(4))->activate([7, 12000, 1, 0, 15, 3000], 4, 0, $full, 0, 0), 'placement');

$oldFixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/arcade-v4-powerups.json'), true, 512, JSON_THROW_ON_ERROR);
$prefix = [];
foreach ($oldFixtures[1]['events'] as $event) {
    if ($event[0] === 7) break;
    $prefix[] = $event;
}
$rejects(fn () => (new RunProofValidator())->validate($proof([...$prefix, [7, 12000, 1, 0, 3, 3000]])), 'Power-up placement');
$blockedFinish = [...$prefix, [10, 12000], [2, 12100, 12100, 0, 0],
    [2, 13600, 13600, 0, 0], [2, 15100, 15100, 0, 0], [5, 15100, 15100]];
$score = (new RunProofValidator())->validate($proof($blockedFinish));
$assert($score->survivalMs === 15100 && $score->misses === 3, 'Full v5 replay accepts2x2 blocked opportunity.');
$rejects(fn () => (new RunProofValidator())->validate($proof($blockedFinish, 'reaction-proof-v4')), 'could have placed');

// Identical to Swift core commit162edc2aef5f9d9f7ada7d54d1b1f305175fc1f0.
// Never replace the retained v4 goldens.
$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/arcade-v5-powerups.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($fixtures as $fixture) {
    $score = (new RunProofValidator())->validate($proof($fixture['events']));
    foreach (['score' => 'score', 'hits' => 'hits', 'misses' => 'misses', 'dodges' => 'dodges',
        'survivalMs' => 'durationMs', 'fastestReactionMs' => 'fastestReactionMs',
        'maxMultiplier' => 'maximumMultiplierUsed'] as $property => $key) {
        $assert($score->{$property} === $fixture['expected'][$key], $fixture['name'] . ' matches Swift ' . $key);
    }
    $assert($score->averageReactionMs === (int) round($fixture['expected']['reactionTotalMs'] / $fixture['expected']['hits']),
        'Swift reaction average is unchanged by the grid gate.');
    $spawns = array_values(array_filter($fixture['events'], static fn (array $event): bool => $event[0] === 7));
    $assert($spawns !== [] && min(array_column($spawns, 1)) >= 40000, 'Every golden spawn occurs on4x4.');
    $rejects(fn () => (new RunProofValidator())->validate($proof($fixture['events'], 'reaction-proof-v4')), 'could have placed');
}

$frozenPrefix = [];
foreach ($fixtures[0]['events'] as $event) {
    $frozenPrefix[] = $event;
    if ($event === [0, 39650, 1, 0]) break;
}
$frozenPrefix = [...$frozenPrefix, [10, 39750], [6, 40000]];
$rejects(fn () => (new RunProofValidator())->validate($proof([...$frozenPrefix, [7, 40000, 1, 0, 2, 3000]])),
    'Power-up placement');
$frozenFinish = [...$frozenPrefix, [10, 40000], [1, 40100, 40100, 1, 2],
    [7, 40250, 1, 0, 1, 3000], [8, 40300, 40300, 1, 1], [4, 40400, 18],
    [2, 40400, 40400, 0, 0], [2, 41900, 41900, 0, 0], [2, 43400, 43400, 0, 0], [5, 43400, 43400]];
$frozenScore = (new RunProofValidator())->validate($proof($frozenFinish));
$assert($frozenScore->misses === 4 && $frozenScore->survivalMs === 43400,
    'Full replay blocks at40s until frozen2x2 target resolves, then accepts visible4x4 pickup.');

fwrite(STDOUT, "Arcade v5 checks passed ({$assertions} assertions).\n");
