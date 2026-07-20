<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\AchievementCatalog;
use SpeedyTapper\CoinEconomy;
use SpeedyTapper\CoinProgression;
use SpeedyTapper\HttpRequest;
use SpeedyTapper\LeaderboardModerationService;
use SpeedyTapper\LeaderboardWindow;
use SpeedyTapper\MigrationRunner;
use SpeedyTapper\Nickname;
use SpeedyTapper\PetCatalog;
use SpeedyTapper\RunAttemptService;
use SpeedyTapper\RunProof;
use SpeedyTapper\RunProofValidator;
use SpeedyTapper\RunSubmissionService;
use SpeedyTapper\ScoreSubmission;
use SpeedyTapper\SessionStore;
use SpeedyTapper\SessionRegistry;
use SpeedyTapper\ThemeCatalog;
use SpeedyTapper\Uuid;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$throwsApi = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (ApiException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$proofPayload = static function (string $runId, string $mode, array $events): array {
    return [
        'runId' => $runId,
        'mode' => $mode,
        'buildId' => RunProof::BUILD_ID,
        'ruleset' => RunProof::RULESET,
        'proofVersion' => RunProof::PROOF_VERSION,
        'events' => $events,
    ];
};

$normalProof = static function (string $runId, array $reactions = [100]) use ($proofPayload): array {
    $events = [];
    $handledAt = 0;
    foreach ($reactions as $hit => $reactionMs) {
        $targetAt = $handledAt + 600;
        $cell = $hit < 4 ? 0 : $hit % 4;
        $inputAt = $targetAt + $reactionMs;
        $handledAt = $inputAt + 2;
        $events[] = [RunProof::EVENT_TARGET, $targetAt, $cell];
        $events[] = [RunProof::EVENT_HIT, $inputAt, $handledAt, $cell];
    }

    for ($miss = 0; $miss < 3; $miss++) {
        $inputAt = $handledAt + 100;
        $handledAt = $inputAt + 2;
        $events[] = [RunProof::EVENT_MISS, $inputAt, $handledAt, RunProof::MISS_EMPTY, 0];
    }
    $events[] = [RunProof::EVENT_FINISH, $events[count($events) - 1][1], $handledAt];
    return $proofPayload($runId, 'normal', $events);
};

$zenProof = static function (string $runId) use ($proofPayload): array {
    $events = [];
    $handledAt = 0;
    $hits = 0;
    $targetDelayMs = 1_000.0;

    while (true) {
        $targetAt = (int) round($handledAt + $targetDelayMs);
        if ($targetAt >= 180_000) break;
        $dimension = $targetAt >= 40_000 ? 4 : ($hits >= 4 ? 2 : 1);
        $cell = $hits % ($dimension ** 2);
        $events[] = [RunProof::EVENT_TARGET, $targetAt, $cell];
        $reactionMs = 90 + ($hits % 21);
        if ($targetAt + $reactionMs >= 180_000) break;
        $inputAt = $targetAt + $reactionMs;
        $handledAt = $inputAt + 2;
        $events[] = [RunProof::EVENT_HIT, $inputAt, $handledAt, $cell];
        $targetDelayMs += 0.5 * ($reactionMs - $targetDelayMs);
        $hits++;
    }

    $events[] = [RunProof::EVENT_FINISH, 180_000, 180_000];
    return $proofPayload($runId, 'zen', $events);
};

$devRouter = file_get_contents(dirname(__DIR__) . '/server/dev-router.php');
$assert(is_string($devRouter), 'PHP development router must be readable.');
$assert(str_contains($devRouter, "require \$projectRoot . '/api/index.php'"), 'PHP development router must dispatch API requests.');
$assert(str_contains($devRouter, '(?:server|vendor|\\.git)'), 'PHP development router must deny internal directories.');

$assert(Nickname::normalize("  Speedy\n  Player  ") === 'Speedy Player', 'Nickname whitespace is normalized.');
$throwsApi(static fn () => Nickname::normalize(str_repeat('x', 21)), 'Long nicknames are rejected.');
$assert((bool) preg_match('/^Player [0-9]{4}$/', Nickname::anonymous()), 'New profiles receive a neutral nickname.');
$assert((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Uuid::v4()), 'UUIDs are RFC 4122 version 4.');
$assert(
    PetCatalog::all() === [
        ['id' => 'foka', 'name' => 'Foka', 'priceCoins' => 10],
        ['id' => 'kesha', 'name' => 'Kesha', 'priceCoins' => 20],
        ['id' => 'tauta', 'name' => 'Tauta', 'priceCoins' => 50],
        ['id' => 'misha', 'name' => 'Misha', 'priceCoins' => 100],
        ['id' => 'pancake', 'name' => 'Pancake', 'priceCoins' => 500],
    ],
    'Pet catalog ids, names, prices, and order are stable.',
);
$throwsApi(static fn () => PetCatalog::require('unknown'), 'Unknown pets are rejected.');
$throwsApi(static fn () => PetCatalog::require('mitsuri'), 'The nickname-only Mitsuri pet cannot be purchased.');
$throwsApi(static fn () => PetCatalog::require('muse'), 'The nickname-only Muse companion cannot be purchased.');
$assert(
    PetCatalog::specialForNickname('кокос', true) === 'mitsuri',
    'The exact confirmed lowercase Cyrillic nickname enables Mitsuri.',
);
$assert(
    PetCatalog::specialForNickname('КОКОС', true) === null
    && PetCatalog::specialForNickname('kokoc', true) === null
    && PetCatalog::specialForNickname('кокос', false) === null,
    'Uppercase, Latin lookalikes, and unconfirmed nicknames do not enable Mitsuri.',
);
$assert(PetCatalog::isRenderable('mitsuri'), 'Mitsuri is a renderable server-authorized cosmetic.');
$assert(
    PetCatalog::specialForNickname('bloodyvlad', true) === 'muse',
    'The exact confirmed bloodyvlad nickname enables Muse.',
);
$assert(
    PetCatalog::specialForNickname('BloodyVlad', true) === null
    && PetCatalog::specialForNickname('bloodyvlad', false) === null,
    'Case variants and unconfirmed nicknames do not enable Muse.',
);
$assert(PetCatalog::isRenderable('muse'), 'Muse is a renderable server-authorized cosmetic.');
$assert(count(AchievementCatalog::all()) === 5, 'The achievement catalog exposes five active goals.');
$assert(
    ThemeCatalog::all() === [
        ['id' => 'classic', 'name' => 'Default', 'priceCoins' => 0],
        ['id' => 'disco', 'name' => 'Disco', 'priceCoins' => 0],
        ['id' => 'light', 'name' => 'Light', 'priceCoins' => 50],
        ['id' => 'pixel', 'name' => 'Pixel', 'priceCoins' => 100],
    ],
    'Theme catalog ids, display names, prices, and order are stable.',
);
$assert(ThemeCatalog::isFree('classic') && ThemeCatalog::isFree('disco'), 'Default and Disco remain free.');
$throwsApi(static fn () => ThemeCatalog::require('unknown'), 'Unknown themes are rejected.');
$throwsApi(
    static fn () => AchievementCatalog::require('complete_zen'),
    'The obsolete timed-Zen achievement cannot be unlocked or claimed.',
);
$assert(
    AchievementCatalog::require(AchievementCatalog::BUY_A_PET)['rewardCoins'] === 10,
    'Buying a pet unlocks the ten-coin achievement reward.',
);
$assert(
    CoinEconomy::applyCredit(0, 4, 3) === ['coins' => 0, 'debt' => 1, 'debtPaid' => 3],
    'New credits pay moderation debt before becoming spendable.',
);
$assert(
    CoinEconomy::applyCredit(2, 0, 3) === ['coins' => 5, 'debt' => 0, 'debtPaid' => 0],
    'Debt-free credits become spendable coins.',
);
$assert(CoinEconomy::fromNet(-4) === ['coins' => 0, 'debt' => 4], 'Negative entitlement becomes coin debt.');
$assert(
    CoinEconomy::applyEarnedCredit(0, 9, 4, 0, 6) === [
        'earnedCoins' => 2,
        'purchasedCoins' => 9,
        'earnedDebt' => 0,
        'refundDebt' => 0,
        'earnedDebtPaid' => 4,
        'refundDebtPaid' => 0,
    ],
    'Earned credits repay refund debt, then earned debt, before becoming spendable.',
);
$assert(
    CoinEconomy::applyEarnedCredit(5, 0, 0, 3, 2) === [
        'earnedCoins' => 5,
        'purchasedCoins' => 0,
        'earnedDebt' => 0,
        'refundDebt' => 1,
        'earnedDebtPaid' => 0,
        'refundDebtPaid' => 2,
    ],
    'Future earned credits clear StoreKit refund debt before becoming spendable.',
);
$assert(
    CoinEconomy::applyPurchasedCredit(0, 0, 3, 5, 8) === [
        'earnedCoins' => 0,
        'purchasedCoins' => 3,
        'earnedDebt' => 3,
        'refundDebt' => 0,
        'refundDebtPaid' => 5,
    ],
    'Purchased credits repay only refund debt before becoming spendable.',
);
$assert(
    CoinEconomy::spendEarnedFirst(4, 7, 0, 0, 9) === [
        'earnedCoins' => 0,
        'purchasedCoins' => 2,
        'earnedDebt' => 0,
        'refundDebt' => 0,
        'earnedSpent' => 4,
        'purchasedSpent' => 5,
    ],
    'Purchases consume earned coins before paid coins.',
);
$assert(
    CoinEconomy::applyPurchasedReversal(11, 3, 0, 0, 8) === [
        'earnedCoins' => 11,
        'purchasedCoins' => 0,
        'earnedDebt' => 0,
        'refundDebt' => 5,
        'purchasedCoinsReversed' => 3,
        'refundDebtAdded' => 5,
    ],
    'A paid-value reversal preserves earned coins and records the uncovered refund debt.',
);
$assert(
    CoinEconomy::summary(7, 0, 0, 3) === [
        'earnedCoins' => 7,
        'purchasedCoins' => 0,
        'earnedDebt' => 0,
        'refundDebt' => 3,
        'coins' => 7,
        'debt' => 3,
        'net' => 4,
    ],
    'Compatibility totals preserve independent provenance even when coins and debt coexist.',
);
$assert(
    CoinEconomy::fromEarnedNet(-4, 10) === [
        'earnedCoins' => 0,
        'purchasedCoins' => 10,
        'earnedDebt' => 4,
        'refundDebt' => 0,
    ],
    'Earned recomputation never nets moderation debt against purchased value.',
);
$throwsInvalidArgument = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (InvalidArgumentException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$throwsInvalidArgument(
    static fn () => CoinEconomy::spendEarnedFirst(2, 3, 0, 0, 6),
    'Spending more than the two spendable provenance buckets is rejected.',
);
$throwsInvalidArgument(
    static fn () => CoinEconomy::summary(1, 0, 1, 0),
    'A single provenance cannot hold both spendable coins and its matching debt.',
);

$unrankedAttemptService = (new ReflectionClass(RunAttemptService::class))->newInstanceWithoutConstructor();
try {
    $unrankedAttemptService->start(
        Uuid::v4(),
        str_repeat('b', 32),
        'zen',
        RunProofValidator::BUILD_ID,
    );
    $assert(false, 'Zen must not issue a ranked run ticket.');
} catch (ApiException $error) {
    $assert(
        $error->status === 409 && str_contains($error->getMessage(), 'unranked practice'),
        'Zen start requests are rejected before any database or coin accounting work.',
    );
}

$unrankedSubmissionService = (new ReflectionClass(RunSubmissionService::class))->newInstanceWithoutConstructor();
$unrankedProof = RunProof::fromArray($zenProof('b2392db0-7cfa-4cbc-a2b2-6dadf2b76310'));
try {
    $unrankedSubmissionService->submit(Uuid::v4(), str_repeat('b', 32), $unrankedProof);
    $assert(false, 'Zen must not submit a leaderboard result.');
} catch (ApiException $error) {
    $assert(
        $error->status === 409 && str_contains($error->getMessage(), 'does not submit scores or award coins'),
        'Zen finish requests are rejected before validation, leaderboard, achievement, or coin work.',
    );
}

$singleHitPayload = $normalProof('4f27f9de-37de-4c31-8090-279a037bf76a');
$singleHit = ScoreSubmission::fromArray($singleHitPayload);
$assert($singleHit->score === 829, 'The server derives the rounded one-hit reaction score.');
$assert($singleHit->hits === 1 && $singleHit->misses === 3, 'The server derives hit and miss totals from proof events.');
$assert($singleHit->godlikeCount === 1 && $singleHit->averageReactionMs === 100, 'The server derives reaction ratings and timing.');
$assert($singleHit->survivalMs === 1_006, 'Arcade survival ends at the third pointer contact.');
$assert(strlen($singleHit->proofHash) === 32 && strlen($singleHit->payloadHash()) === 32, 'Proof and result hashes are fixed binary SHA-256 values.');
$assert($singleHit->isBetterThan(['score' => 800, 'duration_ms' => 10_000, 'correct_taps' => 9]), 'Score remains the first ranking criterion.');

$multiplierPayload = $normalProof('557aa694-d5db-44e6-9d38-b4ce0cdd0462', array_fill(0, 6, 100));
$multiplierRun = ScoreSubmission::fromArray($multiplierPayload);
$assert($multiplierRun->score === 8_290, 'The validator derives weighted streak scoring.');
$assert(
    $multiplierRun->maxMultiplier === 3
        && $multiplierRun->multiplierOneHits === 3
        && $multiplierRun->multiplierTwoHits === 2
        && $multiplierRun->multiplierThreeHits === 1,
    'Godlike overflow advances and uses the expected multiplier buckets.',
);
$assert(
    $multiplierRun->reactionBasePoints === 4_974
        && $multiplierRun->multiplierBonusPoints === 3_316,
    'Server-derived base and multiplier bonus points reconcile to the final score.',
);

$forgedAggregate = $singleHitPayload + [
    'score' => 550_000_000,
    'survivalMs' => 604_800_000,
    'dodges' => 1_000_000,
];
$throwsApi(
    static fn () => ScoreSubmission::fromArray($forgedAggregate),
    'Client-authored score, duration, and dodge aggregates are rejected.',
);
$throwsApi(
    static fn () => ScoreSubmission::fromArray([
        'runId' => '4f27f9de-37de-4c31-8090-279a037bf76a',
        'mode' => 'normal',
        'score' => 550_000_000,
        'survivalMs' => 604_800_000,
    ]),
    'The retired aggregate submission shape cannot create a ranked result.',
);

$wrongCell = $normalProof('1ae1e67d-ec40-48e5-863e-f79239cfcb86', array_fill(0, 5, 100));
$wrongCell['events'][9][3] = 2;
$throwsApi(static fn () => ScoreSubmission::fromArray($wrongCell), 'A claimed hit must match the active target cell.');

$compressed = $normalProof('17bfc901-b1b4-461d-9ed8-e2e63d5e18be');
$compressed['events'][0][1] = 100;
$compressed['events'][1][1] = 200;
$compressed['events'][1][2] = 202;
$throwsApi(static fn () => ScoreSubmission::fromArray($compressed), 'Targets cannot appear before their quiet interval.');

$deadlineHit = $normalProof('a737e938-4d5a-4bc3-a948-152dce3db7ef');
$deadlineHit['events'][1] = [RunProof::EVENT_HIT, 1_600, 1_602, 0];
$throwsApi(
    static fn () => ScoreSubmission::fromArray($deadlineHit),
    'A contact exactly on the response deadline cannot be forged as a correct hit.',
);

$deadlineWrong = $normalProof('d87330e9-df52-464a-8fb3-596a7df8bf6a');
$deadlineWrong['events'][1] = [RunProof::EVENT_MISS, 1_600, 1_602, RunProof::MISS_WRONG, 1];
$throwsApi(
    static fn () => ScoreSubmission::fromArray($deadlineWrong),
    'A contact exactly on the response deadline must be classified late rather than wrong.',
);

$missingFinish = $normalProof('7fef50a2-8a53-4fd8-92ca-8dcc1227b075');
array_pop($missingFinish['events']);
$throwsApi(static fn () => ScoreSubmission::fromArray($missingFinish), 'A proof without its terminal event is rejected.');

$sevenDay = $proofPayload('d4d867d5-4077-45dd-8428-8b652fcf1299', 'normal', [
    [RunProof::EVENT_MISS, 604_800_000, 604_800_000, RunProof::MISS_EMPTY, 0],
    [RunProof::EVENT_MISS, 604_800_100, 604_800_100, RunProof::MISS_EMPTY, 0],
    [RunProof::EVENT_MISS, 604_800_200, 604_800_200, RunProof::MISS_EMPTY, 0],
    [RunProof::EVENT_FINISH, 604_800_200, 604_800_200],
]);
$throwsApi(static fn () => ScoreSubmission::fromArray($sevenDay), 'Fabricated week-long Arcade proofs are rejected.');

$zen = ScoreSubmission::fromArray($zenProof('cc2dc024-3300-4cb8-9d3c-e7f68eb8963c'));
$assert($zen->mode === 'zen' && $zen->survivalMs === 180_000, 'A complete chronological Zen proof ends at exactly three minutes.');
$assert(
    $zen->hits > 100
        && $zen->riskLevel === 'high'
        && in_array('missing_decoy_cadence', $zen->riskFlags, true)
        && in_array('missing_decoy_transitions', $zen->riskFlags, true)
        && in_array('near_uniform_godlike_reactions', $zen->riskFlags, true),
    'A long proof that silently omits the independent decoy engine is held for review.',
);

$persistentZen = $proofPayload('08fc9d30-f3e1-4e6f-9cb8-b223f6df6ec5', 'zen', [
    [RunProof::EVENT_TARGET, 1_000, 0],
    [RunProof::EVENT_HIT, 1_100, 1_102, 0],
    [RunProof::EVENT_TARGET, 1_652, 0],
    [RunProof::EVENT_HIT, 1_752, 1_754, 0],
    [RunProof::EVENT_TARGET, 2_079, 0],
    [RunProof::EVENT_HIT, 2_179, 2_181, 0],
    [RunProof::EVENT_TARGET, 2_394, 0],
    [RunProof::EVENT_HIT, 2_494, 2_496, 0],
    [RunProof::EVENT_TARGET, 2_652, 0],
    [RunProof::EVENT_MISS, 3_082, 3_084, RunProof::MISS_WRONG, 1],
    [RunProof::EVENT_HIT, 3_882, 3_884, 0],
    [RunProof::EVENT_TARGET, 4_577, 0],
    [RunProof::EVENT_FINISH, 180_000, 180_000],
]);
$persistentZenScore = ScoreSubmission::fromArray($persistentZen);
$assert(
    $persistentZenScore->hits === 5
        && $persistentZenScore->misses === 1
        && $persistentZenScore->goodCount === 1,
    'PHP replay retains a Zen target through a wrong tap and accepts its later correct tap.',
);

$tickPayload = $proofPayload('46adf276-4ab7-4ae1-8f5d-ae0ddc3a7131', 'normal', [
    [RunProof::EVENT_DECOY_TICK, 10_000],
]);
$parsedTick = RunProof::fromArray($tickPayload);
$assert($parsedTick->events === [[RunProof::EVENT_DECOY_TICK, 10_000]], 'An ignored decoy opportunity has a compact proof tuple.');
$invalidTick = $tickPayload;
$invalidTick['events'][0][] = 1;
$throwsApi(static fn () => RunProof::fromArray($invalidTick), 'Decoy opportunity tuples reject unsupported fields.');

$equalMillisecondEvents = [];
$handledAt = 0;
for ($hit = 0; $hit < 14; $hit++) {
    $targetAt = $handledAt + 600;
    $cell = $hit < 4 ? 0 : $hit % 4;
    $inputAt = $targetAt + 100;
    $handledAt = $inputAt + 2;
    $equalMillisecondEvents[] = [RunProof::EVENT_TARGET, $targetAt, $cell];
    $equalMillisecondEvents[] = [RunProof::EVENT_HIT, $inputAt, $handledAt, $cell];
}
$equalMillisecondEvents[] = [RunProof::EVENT_DECOY_ACTIVATE, 10_000, 1, 3, 450];
$equalMillisecondEvents[] = [RunProof::EVENT_TARGET, 10_450, 2];
$equalMillisecondEvents[] = [RunProof::EVENT_HIT, 10_550, 10_552, 2];
$handledAt = 10_552;
for ($miss = 0; $miss < 3; $miss++) {
    $inputAt = $handledAt + 100;
    $handledAt = $inputAt + 2;
    $equalMillisecondEvents[] = [RunProof::EVENT_MISS, $inputAt, $handledAt, RunProof::MISS_EMPTY, 0];
}
$equalMillisecondEvents[] = [RunProof::EVENT_FINISH, 10_856, 10_858];
$equalMillisecondProof = $proofPayload(
    '6615c12b-41d0-4f1f-b1f1-62308f06f8de',
    'normal',
    $equalMillisecondEvents,
);
$equalMillisecondRun = ScoreSubmission::fromArray($equalMillisecondProof);
$assert(
    $equalMillisecondRun->hits === 15 && $equalMillisecondRun->dodges === 0,
    'Integer-ms proof replay accepts a target that rounded to the same millisecond as a still-live decoy expiry.',
);
$falseTickProof = $equalMillisecondProof;
$falseTickProof['runId'] = 'ce3cefda-0507-420f-b89c-304d287f5168';
$falseTickProof['events'][28] = [RunProof::EVENT_DECOY_TICK, 10_000];
$throwsApi(
    static fn () => ScoreSubmission::fromArray($falseTickProof),
    'A client cannot claim an ignored decoy opportunity when a decoy could have appeared.',
);

$riskMethod = new ReflectionMethod(RunProofValidator::class, 'assessRisk');
$lagRisk = $riskMethod->invoke(
    new RunProofValidator(),
    array_fill(0, 30, 300),
    array_fill(0, 30, 2),
    [451, 503, 557, 609, 661],
    [0.8, 0.9, 0.7, 0.85, 0.95],
    array_fill(0, 30, 0.5),
    [2 => [0, 1, 2, 3], 4 => range(0, 15)],
    30,
    0,
    0,
    5,
    0,
    5,
    180_000,
    30,
);
$assert(
    $lagRisk[1] === 'high' && in_array('sustained_decoy_scheduler_lag', $lagRisk[2], true),
    'Repeatedly delaying independent decoy timers cannot suppress decoys in a ranked run.',
);

$eliteBotRisk = $riskMethod->invoke(
    new RunProofValidator(),
    [...array_fill(0, 90, 180), ...array_fill(0, 10, 320)],
    array_fill(0, 100, 2),
    [451, 503, 557, 609, 661],
    [0.2, 0.4, 0.6, 0.8, 0.5],
    array_fill(0, 100, 0.5),
    [2 => [0, 1, 2, 3], 4 => range(0, 15)],
    100,
    0,
    0,
    5,
    0,
    0,
    180_000,
    100,
);
$assert(
    $eliteBotRisk[1] === 'high' && in_array('sustained_elite_reactions', $eliteBotRisk[2], true),
    'Sustained automated elite timing is withheld for operator review.',
);

$badZen = $zenProof('5e4f46d1-a132-4b97-b9a1-481090dca940');
$badZen['events'][count($badZen['events']) - 1][1] = 179_999;
$throwsApi(static fn () => ScoreSubmission::fromArray($badZen), 'Zen cannot claim completion before its exact deadline.');

$parsedProof = RunProof::fromArray($singleHitPayload);
$assert(hash_equals($parsedProof->proofHash(), RunProof::fromArray($singleHitPayload)->proofHash()), 'Canonical proof hashes are stable.');
$compatibleBuildProofs = [];
foreach (['20260718-1', '20260719-1', '20260719-2'] as $compatibleBuildId) {
    $compatibleBuildPayload = $singleHitPayload;
    $compatibleBuildPayload['buildId'] = $compatibleBuildId;
    $compatibleBuildProofs[$compatibleBuildId] = RunProof::fromArray($compatibleBuildPayload);
    $assert(
        $compatibleBuildProofs[$compatibleBuildId]->buildId === $compatibleBuildId
            && (new RunProofValidator())->validate($compatibleBuildProofs[$compatibleBuildId])->score === $singleHit->score,
        'Each explicitly compatible build keeps its ticket-bound build ID and replays under the unchanged ruleset.',
    );
}
$assert(
    !hash_equals($parsedProof->proofHash(), $compatibleBuildProofs['20260718-1']->proofHash())
        && hash_equals($parsedProof->traceHash(), $compatibleBuildProofs['20260718-1']->traceHash()),
    'The proof hash binds a compatible build while cross-build trace-clone detection remains stable.',
);
$unsupportedBuildPayload = $singleHitPayload;
$unsupportedBuildPayload['buildId'] = 'future-build';
$throwsApi(
    static fn () => RunProof::fromArray($unsupportedBuildPayload),
    'An unlisted build cannot submit a ranked proof.',
);
$sameTracePayload = $singleHitPayload;
$sameTracePayload['runId'] = 'a4249615-4e43-4de1-b704-87d61647d7d7';
$sameTrace = RunProof::fromArray($sameTracePayload);
$assert(!hash_equals($parsedProof->proofHash(), $sameTrace->proofHash()), 'A full proof hash binds the server-issued run ID.');
$assert(hash_equals($parsedProof->traceHash(), $sameTrace->traceHash()), 'A trace hash detects cloned event streams across run IDs.');
$futureMetadataTrace = new RunProof(
    runId: '90bcff87-3778-44aa-be98-11622996a759',
    mode: $parsedProof->mode,
    buildId: 'future-build',
    ruleset: 'future-ruleset',
    proofVersion: 99,
    events: $parsedProof->events,
);
$assert(
    hash_equals($parsedProof->traceHash(), $futureMetadataTrace->traceHash()),
    'Exact event replay detection cannot be reset merely by deploying a new build.',
);
$invalidTuple = $singleHitPayload;
$invalidTuple['events'][0][1] = 600.5;
$throwsApi(static fn () => RunProof::fromArray($invalidTuple), 'Proof tuple values must be integers.');

$firstHalfMinute = CoinProgression::accrue(0, 30_000);
$secondHalfMinute = CoinProgression::accrue($firstHalfMinute->remainderMs, 30_000);
$assert($firstHalfMinute->coinsEarned === 0, 'An incomplete cumulative minute does not award a coin yet.');
$assert($secondHalfMinute->coinsEarned === 1 && $secondHalfMinute->remainderMs === 0, 'Verified partial run time carries into the next eligible run.');
$threeMinuteProgression = CoinProgression::accrue(0, ScoreSubmission::ZEN_DURATION_MS);
$assert(
    $threeMinuteProgression->coinsEarned === 3 && $threeMinuteProgression->remainderMs === 0,
    'The generic progression helper remains duration-based; unranked Zen is rejected before accounting.',
);
$assert(
    CoinProgression::accrue(0, $singleHit->survivalMs) == CoinProgression::accrue(0, 1_006),
    'Coin accounting depends on derived play time, not score or multiplier.',
);

$rows = [];
for ($rank = 1; $rank <= 12; $rank++) {
    $rows[] = [
        'id' => $rank === 9 ? 'target-result' : 'result-' . $rank,
        'rank_position' => $rank,
        'player_id' => $rank === 9 ? 'target-player' : 'player-' . $rank,
    ];
}
$window = LeaderboardWindow::select($rows, 'target-result');
$assert(array_column($window['rows'], 'rank_position') === [1, 2, 3, 4, 5, 7, 8, 9, 10, 11], 'Top five and result context are combined without filler rows.');
$assert($window['contextRank'] === 9, 'The requested result rank is returned.');
$assert(LeaderboardWindow::topPercent(9, 100) === 9, 'Top percentage is rounded upward.');

$emptyObject = new HttpRequest('POST', '/api/profile', [], [], '{}');
$assert($emptyObject->json() === [], 'An empty JSON object is accepted as an object.');
$throwsApi(static fn () => (new HttpRequest('POST', '/api/profile', [], [], '[]'))->json(), 'A JSON list is rejected at the API boundary.');
$csrfRequest = new HttpRequest('POST', '/api/runs', [], ['HTTP_X_SPEEDYTAPPER_CSRF' => 'token'], '{}');
$assert($csrfRequest->header('X-SpeedyTapper-CSRF') === 'token', 'Security headers are read case-insensitively from the PHP request map.');
$throwsApi(
    static fn () => (new HttpRequest('POST', '/api/profile', [], [
        'HTTP_ORIGIN' => 'http://speedytapper.otcsoft.com',
        'HTTP_HOST' => 'speedytapper.otcsoft.com',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ], '{}'))->guardSameOriginMutation(),
    'A mutation from the wrong origin scheme is rejected.',
);

session_id('speedytappersecuritytest' . bin2hex(random_bytes(4)));
$ratePlayerId = '0e15330a-720c-42d2-88c4-18b881388b8a';
$rateDatabase = new PDO('sqlite::memory:');
$rateDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rateDatabase->exec('CREATE TABLE players (id TEXT PRIMARY KEY)');
$rateDatabase->exec(
    'CREATE TABLE player_sessions ('
    . 'session_auth_hash BLOB PRIMARY KEY, player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
    . 'expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'
);
$rateDatabase->prepare('INSERT INTO players (id) VALUES (:id)')->execute(['id' => $ratePlayerId]);
$rateSession = new SessionStore(false, new SessionRegistry($rateDatabase));
$rateSession->login($ratePlayerId);
for ($attempt = 0; $attempt < 20; $attempt++) {
    $rateSession->requireRunFinishCapacity();
}
$rateSession->login($ratePlayerId);
$rateSession->requireRecentPrimaryAuthentication();
$assert(true, 'A freshly verified primary login satisfies sensitive authentication.');
$_SESSION['speedytapper_primary_authenticated_at'] = time() - 901;
$throwsApi(
    static fn () => $rateSession->requireRecentPrimaryAuthentication(),
    'A stale browser session must reauthenticate before a sensitive mutation.',
);
$rateSession->login($ratePlayerId, 'apple');
$rateSession->requireRecentPrimaryAuthentication();
$assert(true, 'A freshly verified Apple login satisfies sensitive authentication.');
$_SESSION['speedytapper_primary_authenticated_provider'] = 'game_center';
$throwsApi(
    static fn () => $rateSession->requireRecentPrimaryAuthentication(),
    'Game Center cannot satisfy primary-account reauthentication.',
);
$rateSession->login($ratePlayerId);
$throwsApi(
    static fn () => $rateSession->requireRunFinishCapacity(),
    'Malformed finish requests are capped before proof parsing and re-login cannot reset that session limit.',
);
$rateSession->logout();

$schema = '';
foreach (glob(dirname(__DIR__) . '/server/migrations/*.sql') ?: [] as $migrationPath) {
    $schema .= file_get_contents($migrationPath);
}
foreach ([
    'google_subject_hash',
    'player_identities',
    'player_game_center_bindings',
    'player_apple_authorizations',
    'game_center_assertion_uses',
    "provider IN ('google', 'apple')",
    'nickname_confirmed',
    'completed_runs',
    'run_attempts',
    'run_attempts_player_submission_index',
    'run_attempts_status_updated_index',
    'run_proofs',
    'run_trace_claims',
    'trace_hash',
    'session_binding_hash',
    'credited_play_ms',
    'verification_status',
    'coin_ledger',
    'leaderboard_moderation_events',
    'completed_runs_leaderboard_entry_unique',
    'player_pets',
    'player_pet_selection',
    'player_themes',
    'player_theme_selection',
    'is_visible',
    'player_pet_selection_owned_foreign',
    'legacy_easter_egg',
    'admin_test_grant',
    'player_achievements',
    'total_coins_collected',
    'coin_debt',
    'pet_purchase',
    'theme_purchase',
    'achievement_reward',
    'coin_debt_after',
    'player_roles',
    'leaderboard_admin',
    'economy_generation',
    'account_reward_resets',
    'admin_reward_reset',
    'delete_reset',
    'themes_removed',
    'theme_ids_json',
] as $needle) {
    $assert(str_contains($schema, $needle), 'Schema contains ' . $needle . '.');
}
$assert(str_contains($schema, "ENUM(''legacy'',''verified'',''review'',''quarantined'',''deleted'')"), 'Schema preserves auditable verification and moderation states.');
$assert(
    str_contains($schema, "arcade.id = 'd4e98497-9212-475e-8664-283171ce3910'")
        && str_contains($schema, "zen.id = '82ee646d-28d9-43f8-9e38-e4e234a02db1'")
        && str_contains($schema, 'zen.player_id = arcade.player_id')
        && str_contains($schema, "arcade.mode = 'normal'")
        && str_contains($schema, 'arcade.score = 77825')
        && str_contains($schema, "zen.mode = 'zen'")
        && str_contains($schema, "verification_status IN ('legacy', 'verified')"),
    'Administrator bootstrap requires the exact production result pair to share one player.',
);
$adminCosmeticMigration = file_get_contents(
    dirname(__DIR__) . '/server/migrations/013_grant_admin_test_cosmetics.sql'
);
$assert(
    is_string($adminCosmeticMigration)
        && str_contains($adminCosmeticMigration, "admin_role.role = 'leaderboard_admin'")
        && str_contains($adminCosmeticMigration, "admin_role.granted_by = 'migration-011'")
        && str_contains($adminCosmeticMigration, 'CROSS JOIN pets AS pet')
        && str_contains($adminCosmeticMigration, 'pet.active = 1')
        && str_contains($adminCosmeticMigration, "'admin_test_grant'")
        && str_contains($adminCosmeticMigration, 'CROSS JOIN themes AS theme')
        && str_contains($adminCosmeticMigration, 'theme.active = 1')
        && str_contains($adminCosmeticMigration, 'theme.price_coins > 0')
        && substr_count($adminCosmeticMigration, 'ON DUPLICATE KEY UPDATE') === 2,
    'Admin test entitlements grant every active shop pet and paid theme through the immutable bootstrap role.',
);
foreach ([
    'coin_ledger',
    'player_achievements',
    'player_pet_selection',
    'player_theme_selection',
    'UPDATE players',
] as $forbiddenAdminGrantSideEffect) {
    $assert(
        !str_contains($adminCosmeticMigration, $forbiddenAdminGrantSideEffect),
        'Admin test entitlements avoid ' . $forbiddenAdminGrantSideEffect . '.',
    );
}

$app = file_get_contents(dirname(__DIR__) . '/server/src/App.php');
foreach (['/api/session', '/api/auth/google', '/api/auth/apple/challenge', '/api/auth/apple', '/api/profile/identities/google', '/api/profile/game-center/challenge', '/api/profile/game-center', '/api/logout', '/api/profile', '/api/leaderboard', '/api/top-scores', '/api/pets', '/api/pets/select', '/api/pets/selection', '/api/themes', '/api/themes/select', '/api/achievements', '/api/achievements/claim', '/api/runs', '/api/runs/abandon', '/api/runs/finish'] as $route) {
    $assert(is_string($app) && str_contains($app, $route), 'API includes ' . $route . '.');
}
$assert(
    str_contains($app, '/api/admin/leaderboard')
        && str_contains($app, '/entries/([0-9a-fA-F-]{36})/(quarantine|delete-reset)')
        && str_contains($app, 'requireAdmin(true)')
        && str_contains($app, 'requireRecentPrimaryAuthentication')
        && str_contains($app, "(\$body['confirm'] ?? null) !== true")
        && str_contains($app, "\$body['confirmPlayerId'] ?? null"),
    'Admin reads are role-gated and exact-result mutations require target confirmation plus recent primary auth.',
);
$assert(
    !str_contains($app, '/api/auth/game-center')
        && !str_contains($app, "['gamePlayerId'")
        && str_contains($app, "['challengeId', 'teamPlayerId', 'publicKeyUrl', 'signature', 'salt', 'timestamp']"),
    'Game Center is link-only and accepts only the teamPlayerID covered by Apple\'s signature.',
);
$assert(str_contains($app, 'guardMutation($request)'), 'Every API mutation uses the shared same-origin and CSRF guard.');
$assert(str_contains($app, 'Aggregate score submission is retired'), 'The aggregate score endpoint is explicitly retired.');
$assert(
    preg_match('~rankedRunContext\(true\).*?RunProof::fromArray~s', $app) === 1
        && preg_match('~if \(\$countFinishRequest\).*?requireRunFinishCapacity\(\).*?session->close\(\)~s', $app) === 1,
    'Finish requests consume pre-parse session capacity before proof normalization.',
);
$assert(
    preg_match("~'/api/runs'.*?rankedRunContext\(false\).*?attempts->start~s", $app) === 1
        && str_contains($app, "\$identity['nicknameConfirmed']"),
    'Only an authenticated player with a confirmed nickname can issue a ranked attempt.',
);

$attemptService = file_get_contents(dirname(__DIR__) . '/server/src/RunAttemptService.php');
$assert(
    is_string($attemptService)
        && str_contains($attemptService, 'SELECT id FROM players')
        && str_contains($attemptService, "WHERE player_id = :player_id AND status = 'issued'")
        && str_contains($attemptService, '(run_id, session_binding_hash, player_id, mode'),
    'Ranked starts serialize on the player and abandon any overlapping player attempt.',
);

$runService = file_get_contents(dirname(__DIR__) . '/server/src/RunSubmissionService.php');
$assert(
    is_string($runService)
        && str_contains($runService, 'server_elapsed_ms')
        && str_contains($runService, 'SERVER_CLOCK_TOLERANCE_MS')
        && str_contains($runService, 'MAX_UNACCOUNTED_SERVER_MS')
        && str_contains($runService, 'min($score->survivalMs, $serverElapsedMs)')
        && str_contains($runService, 'SUBMISSION_LIMIT_PER_DAY')
        && str_contains($runService, "'redacted' => true")
        && str_contains($runService, '$this->validator->validate($proof)')
        && str_contains($runService, 'duplicate_event_trace')
        && str_contains($runService, "? 'quarantined'")
        && str_contains($runService, "'review'")
        && str_contains($runService, "'withheld'")
        && str_contains($runService, 'CoinProgression::accrue')
        && str_contains($runService, 'FOR UPDATE'),
    'Run completion is clock-covered, replayed, risk-gated, coin-accounted, and transactional.',
);

$leaderboardRepository = file_get_contents(dirname(__DIR__) . '/server/src/LeaderboardRepository.php');
$assert(
    is_string($leaderboardRepository)
        && str_contains($leaderboardRepository, "verification_status IN ('legacy', 'verified')")
        && str_contains($leaderboardRepository, "['verified', 'review', 'quarantined']")
        && str_contains($leaderboardRepository, "\$parameters['id'] = \$score->runId")
        && str_contains($leaderboardRepository, 'LEFT JOIN player_pet_selection')
        && str_contains($leaderboardRepository, 'ps.is_visible = 1')
        && str_contains($leaderboardRepository, "'petId' =>")
        && str_contains($leaderboardRepository, 'PetCatalog::specialForNickname')
        && !str_contains($leaderboardRepository, 'UPDATE leaderboard_entries'),
    'Only ranked verification states are visible and accepted result rows remain immutable.',
);

$moderationService = file_get_contents(dirname(__DIR__) . '/server/src/LeaderboardModerationService.php');
$moderationCli = file_get_contents(dirname(__DIR__) . '/server/bin/leaderboard-admin.php');
$moderationReflection = new ReflectionClass(LeaderboardModerationService::class);
$moderationWithoutDatabase = $moderationReflection->newInstanceWithoutConstructor();
$filterWhere = $moderationReflection->getMethod('filterWhere');
$defaultStatusParameters = [];
$defaultStatusArguments = [null, null, null, &$defaultStatusParameters];
$defaultStatusWhere = $filterWhere->invokeArgs($moderationWithoutDatabase, $defaultStatusArguments);
$deletedStatusParameters = [];
$deletedStatusArguments = [null, null, 'deleted', &$deletedStatusParameters];
$deletedStatusWhere = $filterWhere->invokeArgs($moderationWithoutDatabase, $deletedStatusArguments);
$assert(
    $defaultStatusWhere === "WHERE l.verification_status <> 'deleted'"
        && $defaultStatusParameters === []
        && $deletedStatusWhere === 'WHERE l.verification_status = :verification_status'
        && $deletedStatusParameters === ['verification_status' => 'deleted'],
    'Admin lists hide deleted results by default and expose them only through the explicit deleted filter.',
);
$assert(
    is_string($moderationService)
        && str_contains($moderationService, 'quarantine')
        && str_contains($moderationService, 'restore')
        && str_contains($moderationService, 'to_status = :current_status')
        && str_contains($moderationService, "currentStatus !== 'quarantined'")
        && str_contains($moderationService, 'must be quarantined before logical deletion')
        && str_contains($moderationService, 'COALESCE(credited_play_ms, LEAST(duration_ms')
        && str_contains($moderationService, 'lockEntryPlayer')
        && str_contains($moderationService, 'recomputePlayerCoins')
        && is_string($moderationCli)
        && str_contains($moderationCli, '--apply')
        && str_contains($moderationCli, "\$name === 'apply'")
        && str_contains($moderationCli, "(\$options['apply'] ?? false) === true")
        && str_contains($moderationCli, '--entry='),
    'Exact-ID moderation is reversible, audited, and dry-run by default.',
);
$assert(
    is_string($moderationService)
        && str_contains($moderationService, 'assertAdminActor')
        && str_contains($moderationService, 'hash_equals($confirmPlayerId, $targetPlayerId)')
        && !str_contains($moderationService, 'Administrators cannot moderate their own results')
        && !str_contains($moderationService, 'Administrator results cannot be moderated here')
        && str_contains($moderationService, "currentStatus !== 'quarantined'")
        && str_contains($moderationService, 'deleteAndReset')
        && str_contains($moderationService, 'account_reward_resets')
        && str_contains($moderationService, 'economy_generation = :economy_generation')
        && str_contains($moderationService, 'removeUnpaidCosmetics')
        && str_contains($moderationService, "allocation.source = 'purchased'")
        && !str_contains($moderationService, 'purchased_coins = 0')
        && !str_contains($moderationService, 'refund_coin_debt = 0')
        && str_contains($moderationService, "rejection_code = 'admin-reward-reset'")
        && str_contains($moderationService, 'total_coins_collected = 0')
        && str_contains($moderationService, "'admin_reward_reset'")
        && str_contains($moderationService, "'delete_reset'")
        && str_contains($moderationService, 'achievementsPreserved'),
    'Role-authorized moderation can target any exact result while preserving purchased value and paid-funded cosmetics.',
);
$purgeCli = file_get_contents(dirname(__DIR__) . '/server/bin/purge-run-attempts.php');
$assert(
    is_string($purgeCli)
        && str_contains($purgeCli, "status = 'rejected'")
        && str_contains($purgeCli, "status IN ('issued','abandoned','expired')")
        && str_contains($purgeCli, '--apply'),
    'Stale unranked attempt cleanup is bounded, explicit, and dry-run by default.',
);

$petShopService = file_get_contents(dirname(__DIR__) . '/server/src/PetShopService.php');
$assert(
    is_string($petShopService)
        && str_contains($petShopService, 'beginTransaction')
        && str_contains($petShopService, 'CoinWalletRepository')
        && str_contains($petShopService, 'wallets->lock')
        && str_contains($petShopService, 'wallets->spend')
        && str_contains($petShopService, 'INSERT INTO player_pets')
        && str_contains($petShopService, 'unlockBuyPetInTransaction')
        && str_contains($petShopService, 'pet_purchase')
        && str_contains($petShopService, 'ON DUPLICATE KEY UPDATE pet_id')
        && str_contains($petShopService, 'is_visible = 1')
        && str_contains($petShopService, 'setVisibility')
        && str_contains($petShopService, 'rollBack'),
    'Buy and Select share one atomic, guarded, retry-safe transaction while visibility is durable.',
);

$themeShopService = file_get_contents(dirname(__DIR__) . '/server/src/ThemeShopService.php');
$assert(
    is_string($themeShopService)
        && str_contains($themeShopService, 'beginTransaction')
        && str_contains($themeShopService, 'CoinWalletRepository')
        && str_contains($themeShopService, 'wallets->lock')
        && str_contains($themeShopService, 'wallets->spend')
        && str_contains($themeShopService, 'INSERT INTO player_themes')
        && str_contains($themeShopService, 'player_theme_selection')
        && str_contains($themeShopService, 'theme_purchase')
        && str_contains($themeShopService, "'theme:' . \$playerId")
        && str_contains($themeShopService, "\$player['economy_generation']")
        && !str_contains($themeShopService, 'unlockBuyPetInTransaction')
        && str_contains($themeShopService, 'rollBack'),
    'Paid themes use an atomic generation-qualified purchase and selection transaction without pet achievements.',
);
$assert(
    preg_match(
        '~wallets->spend.*?INSERT INTO player_pets.*?unlockBuyPetInTransaction.*?player_pet_selection.*?commit\(\)~s',
        $petShopService,
    ) === 1,
    'Buy a pet unlocks only inside the successful debit, ownership, selection, ledger, and commit path.',
);

$achievementService = file_get_contents(dirname(__DIR__) . '/server/src/AchievementService.php');
$assert(
    is_string($achievementService)
        && str_contains($achievementService, 'unlockBuyPetInTransaction')
        && str_contains($achievementService, "\$score->mode !== 'normal'")
        && !str_contains($achievementService, 'COMPLETE_ZEN')
        && str_contains($achievementService, "verification_status = 'verified'")
        && str_contains($achievementService, "acquisition_source = 'purchase'")
        && str_contains($achievementService, 'achievement_reward')
        && str_contains($achievementService, 'wallets->creditEarned')
        && str_contains($achievementService, 'allocateRefundDebtPayment'),
    'Achievement unlocks use verified runs and durable moderation-safe rewards.',
);
$assert(
    str_contains($achievementService, 'run.economy_generation = :economy_generation')
        && str_contains($achievementService, "':g' . \$economyGeneration"),
    'Historical achievements remain durable while new reward eligibility and ledger keys use the active economy generation.',
);
$assert(
    str_contains($schema, 'event_key VARCHAR(128)')
        && str_contains($petShopService, "':g' . \$player['economy_generation']")
        && str_contains($achievementService, "':g' . \$player['economy_generation']"),
    'Generation-qualified repurchase and reward keys fit the expanded immutable ledger key.',
);

$achievementMigration = file_get_contents(dirname(__DIR__) . '/server/migrations/008_player_achievements.sql');
$assert(
    is_string($achievementMigration)
        && str_contains($achievementMigration, "verification_status = 'verified'")
        && str_contains($achievementMigration, "coin_status = 'eligible'")
        && str_contains($achievementMigration, "'DO 1'"),
    'Achievement migration backfills only verified eligible play and reruns with executable no-ops.',
);

$moderationService = file_get_contents(dirname(__DIR__) . '/server/src/LeaderboardModerationService.php');
$assert(
    is_string($moderationService)
        && str_contains($moderationService, "ledger.event_type = 'achievement_reward'")
        && str_contains($moderationService, "allocation.source = 'earned'")
        && str_contains($moderationService, 'earned_refund_settlement')
        && str_contains($moderationService, 'CoinEconomy::fromEarnedNet')
        && str_contains($moderationService, "=== 'zen' ? 0 : self::DODGE_POINTS")
        && str_contains($moderationService, 'coin_debt_after'),
    'Moderation preserves purchases and rewards while reconciling spendable coins or debt.',
);
$assert(
    is_string($runService)
        && str_contains($runService, '(run_id, leaderboard_entry_id, player_id, economy_generation')
        && str_contains($runService, 'player_id, economy_generation, run_id, event_type'),
    'New completed runs and run credits are tagged with the locked player economy generation.',
);
$playerRepository = file_get_contents(dirname(__DIR__) . '/server/src/PlayerRepository.php');
$assert(
    is_string($playerRepository)
        && str_contains($playerRepository, "role.role = 'leaderboard_admin'")
        && str_contains($playerRepository, "'isAdmin' =>")
        && str_contains($playerRepository, "'ownedThemeIds' =>")
        && str_contains($playerRepository, "'selectedThemeId' =>")
        && str_contains($playerRepository, 'PetCatalog::specialForNickname')
        && str_contains($playerRepository, "'specialPetId' =>"),
    'Profile capabilities, nickname cosmetics, and theme ownership are server-authoritative without raw identity data.',
);

$migrationStatements = MigrationRunner::splitStatements(
    "CREATE TABLE example (id INT);\nINSERT INTO example (id) VALUES (1);\n",
);
$assert(count($migrationStatements) === 2, 'Migration SQL is split into executable statements.');

$apiBootstrap = file_get_contents(dirname(__DIR__) . '/api/index.php');
$deploymentBootstrap = file_get_contents(dirname(__DIR__) . '/server/src/DeploymentBootstrap.php');
$assert(str_contains($apiBootstrap, 'new RunAttemptService') && str_contains($apiBootstrap, 'new RunProofValidator'), 'The HTTP API wires issued attempts to server proof replay.');
$assert(
    str_contains($apiBootstrap, 'new PlayerIdentityService')
        && str_contains($apiBootstrap, 'new AppleSignInIdentityVerifier')
        && str_contains($apiBootstrap, 'new AppleSignInTokenClient')
        && str_contains($apiBootstrap, 'new AppleCredentialRepository')
        && str_contains($apiBootstrap, 'new GameCenterIdentityVerifier'),
    'The HTTP API wires provider resolution, Apple code exchange/credential retention, and Game Center verification.',
);
$assert(
    str_contains($app, "['challengeId', 'state', 'identityToken', 'authorizationCode']")
        && str_contains($app, 'storeOrRetainInCurrentTransaction')
        && str_contains($app, '->revoke($refreshToken)'),
    'Apple sign-in requires a one-time code and retains revocation material atomically for deletion.',
);
$assert(
    is_string($deploymentBootstrap)
        && str_contains($deploymentBootstrap, "server/.migrations-pending")
        && str_contains($deploymentBootstrap, 'MigrationRunner')
        && str_contains($deploymentBootstrap, "'.claimed-'")
        && str_contains($deploymentBootstrap, 'rename($markerPath, $claimPath)')
        && str_contains($deploymentBootstrap, 'restoreClaim($claimPath, $markerPath)')
        && str_contains($deploymentBootstrap, 'ensureSeason')
        && str_contains($apiBootstrap, 'DeploymentBootstrap::migrateIfMarked')
        && !str_contains($apiBootstrap, 'new MigrationRunner')
        && !str_contains($apiBootstrap, '$leaderboard->ensureSeason()'),
    'Normal API requests skip migration and season writes while bootstrap atomically claims only its deployment marker.',
);
$migrationRunnerSource = file_get_contents(dirname(__DIR__) . '/server/src/MigrationRunner.php');
$assert(
    is_string($migrationRunnerSource)
        && str_contains($migrationRunnerSource, '$this->database->inTransaction()')
        && str_contains($migrationRunnerSource, '$this->database->rollBack()')
        && str_contains($migrationRunnerSource, 'throw $error;'),
    'A failed migration rolls back any transaction it left active before releasing the advisory lock.',
);
$bootstrapMarkerDirectory = sys_get_temp_dir()
    . '/speedytapper-bootstrap-marker-'
    . bin2hex(random_bytes(8));
mkdir($bootstrapMarkerDirectory, 0700, true);
$bootstrapMarkerPath = $bootstrapMarkerDirectory . '/.migrations-pending';
$bootstrapClaimPath = $bootstrapMarkerPath . '.claimed-test';
$restoreBootstrapClaim = (new ReflectionClass(\SpeedyTapper\DeploymentBootstrap::class))
    ->getMethod('restoreClaim');
try {
    file_put_contents($bootstrapClaimPath, 'older-release');
    file_put_contents($bootstrapMarkerPath, 'newer-release');
    $restoreBootstrapClaim->invoke(null, $bootstrapClaimPath, $bootstrapMarkerPath);
    $assert(
        file_get_contents($bootstrapMarkerPath) === 'newer-release'
            && !is_file($bootstrapClaimPath),
        'A failed older migration claim cannot delete the pending marker from a newer deployment.',
    );

    unlink($bootstrapMarkerPath);
    file_put_contents($bootstrapClaimPath, 'retry-release');
    $restoreBootstrapClaim->invoke(null, $bootstrapClaimPath, $bootstrapMarkerPath);
    $assert(
        file_get_contents($bootstrapMarkerPath) === 'retry-release'
            && !is_file($bootstrapClaimPath),
        'A failed migration restores its claim for a later request when no newer marker exists.',
    );
} finally {
    if (is_file($bootstrapClaimPath)) unlink($bootstrapClaimPath);
    if (is_file($bootstrapMarkerPath)) unlink($bootstrapMarkerPath);
    rmdir($bootstrapMarkerDirectory);
}
$leaderboardRepository = file_get_contents(dirname(__DIR__) . '/server/src/LeaderboardRepository.php');
$assert(
    is_string($leaderboardRepository)
        && str_contains($leaderboardRepository, 'public function topPayload')
        && str_contains($leaderboardRepository, 'ORDER BY ' . "' . \$order . '" . ' LIMIT ')
        && str_contains($app, "'Cache-Control' => 'public, max-age=5, s-maxage=10, stale-while-revalidate=30'"),
    'Public top-five reads use a bounded ordered query and short shared-cache headers.',
);
$sessionStoreSource = file_get_contents(dirname(__DIR__) . '/server/src/SessionStore.php');
$assert(
    is_string($sessionStoreSource)
        && str_contains($sessionStoreSource, 'public function close(): void')
        && str_contains($sessionStoreSource, 'session_write_close()')
        && str_contains($app, 'findRunIdentity')
        && str_contains($app, '$this->session->close();'),
    'Ranked request authentication is lightweight and releases PHP session locks before database work.',
);
$assert(
    str_contains($apiBootstrap, 'new PetShopService')
        && str_contains($apiBootstrap, 'new ThemeShopService')
        && str_contains($apiBootstrap, 'new PlayerRepository($database, $pets, $themes)')
        && str_contains($apiBootstrap, 'pets: $pets')
        && str_contains($apiBootstrap, 'themes: $themes'),
    'The API injects shared pet and theme services into profile reads and shop mutations.',
);

$configSource = file_get_contents(dirname(__DIR__) . '/server/src/Config.php');
$gitignore = file_get_contents(dirname(__DIR__) . '/.gitignore');
$htaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
$assert(
    is_string($configSource)
        && str_contains($configSource, 'SPEEDYTAPPER_CONFIG_PATH')
        && str_contains($configSource, '/.config/speedytapper/config.php')
        && str_contains($configSource, '/server/config.local.php'),
    'Configuration prefers private paths and retains the ignored artifact fallback.',
);
$assert(is_string($gitignore) && str_contains($gitignore, 'server/config.local.php'), 'The local production configuration cannot be committed accidentally.');
$assert(
    is_string($htaccess)
        && str_contains($htaccess, '(?:server|vendor|\.git)')
        && str_contains($htaccess, 'X-Frame-Options')
        && str_contains($htaccess, 'Content-Security-Policy')
        && !str_contains($htaccess, "script-src 'self' 'unsafe-inline'")
        && str_contains($htaccess, 'Strict-Transport-Security'),
    'The production web server denies internals and emits baseline security headers.',
);

fwrite(STDOUT, 'PHP backend tests passed (' . $assertions . ' assertions).' . PHP_EOL);
