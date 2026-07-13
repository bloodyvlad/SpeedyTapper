<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\AchievementCatalog;
use SpeedyTapper\CoinProgression;
use SpeedyTapper\LeaderboardWindow;
use SpeedyTapper\Nickname;
use SpeedyTapper\ScoreSubmission;
use SpeedyTapper\Uuid;
use SpeedyTapper\HttpRequest;
use SpeedyTapper\MigrationRunner;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$devRouter = file_get_contents(dirname(__DIR__) . '/server/dev-router.php');
$assert(is_string($devRouter), 'PHP development router must be readable.');
$assert(str_contains($devRouter, "require \$projectRoot . '/api/index.php'"), 'PHP development router must dispatch API requests.');
$assert(str_contains($devRouter, '(?:server|vendor|\\.git)'), 'PHP development router must deny internal directories.');

$throwsApi = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (ApiException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$assert(Nickname::normalize("  Speedy\n  Player  ") === 'Speedy Player', 'Nickname whitespace is normalized.');
$throwsApi(static fn () => Nickname::normalize(str_repeat('x', 21)), 'Long nicknames are rejected.');
$assert((bool) preg_match('/^Player [0-9]{4}$/', Nickname::anonymous()), 'New profiles receive a neutral nickname.');
$assert((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Uuid::v4()), 'UUIDs are RFC 4122 version 4.');

$valid = ScoreSubmission::fromArray([
    'runId' => '4f27f9de-37de-4c31-8090-279a037bf76a',
    'mode' => 'normal',
    'score' => 4_550,
    'reactionBasePoints' => 4_000,
    'multiplierBonusPoints' => 0,
    'maxMultiplier' => 1,
    'multiplierHitCounts' => ['one' => 4, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
    'multiplierBasePoints' => ['one' => 4_000, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
    'hits' => 4,
    'dodges' => 1,
    'survivalMs' => 91_000,
    'fastestReactionMs' => 178,
    'averageReactionMs' => 331,
    'speedRatings' => ['godlike' => 1, 'perfect' => 1, 'great' => 1, 'good' => 1],
]);
$assert($valid->godlikeCount === 1 && $valid->hits === 4, 'Validated speed-rating counts are retained.');
$assert($valid->runId === '4f27f9de-37de-4c31-8090-279a037bf76a', 'Validated run UUIDs are retained.');
$assert(strlen($valid->payloadHash()) === 32, 'Validated runs produce a fixed binary idempotency hash.');
$assert($valid->isBetterThan(['score' => 4_500, 'duration_ms' => 100_000, 'correct_taps' => 8]), 'Score is the first ranking criterion.');
$assert($valid->isBetterThan(['score' => 4_550, 'duration_ms' => 90_000, 'correct_taps' => 5]), 'Normal duration breaks score ties.');
$zenWithoutDodgeBonus = ScoreSubmission::fromArray([
    'runId' => '3e9292a6-5af7-4bed-a948-54a97f53f8ac',
    'mode' => 'zen',
    'score' => 1_000,
    'reactionBasePoints' => 1_000,
    'multiplierBonusPoints' => 0,
    'maxMultiplier' => 1,
    'multiplierHitCounts' => ['one' => 1, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
    'multiplierBasePoints' => ['one' => 1_000, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
    'hits' => 1,
    'dodges' => 2,
    'survivalMs' => ScoreSubmission::ZEN_DURATION_MS,
    'fastestReactionMs' => 90_000,
    'averageReactionMs' => 90_000,
    'speedRatings' => ['godlike' => 0, 'perfect' => 0, 'great' => 0, 'good' => 1],
]);
$assert(
    $zenWithoutDodgeBonus->dodges === 2 && $zenWithoutDodgeBonus->score === 1_000,
    'Zen accepts infinite-target reactions and tracks dodges without adding dodge points.',
);
$zenWithoutDodgeBonus->assertAcceptedAsNewRun();
$legacyZenRetry = ScoreSubmission::fromArray([
    'runId' => '6b3d5a14-9b78-4d88-94db-b0e1cecae183',
    'mode' => 'zen',
    'score' => 2_100,
    'reactionBasePoints' => 1_000,
    'multiplierBonusPoints' => 0,
    'maxMultiplier' => 1,
    'multiplierHitCounts' => ['one' => 1, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
    'multiplierBasePoints' => ['one' => 1_000, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
    'hits' => 1,
    'dodges' => 2,
    'survivalMs' => ScoreSubmission::ZEN_DURATION_MS,
    'fastestReactionMs' => 200,
    'averageReactionMs' => 200,
    'speedRatings' => ['godlike' => 1, 'perfect' => 0, 'great' => 0, 'good' => 0],
]);
$assert(
    $legacyZenRetry->usesLegacyZenDodgeScore,
    'Legacy Zen dodge scoring remains parseable only for completed-run retry matching.',
);
$assert(
    bin2hex($legacyZenRetry->payloadHash()) === 'b91d44c87a1e6dde6dd598534d05ba62feb85538e3e877d671a68277140f51e5',
    'Legacy Zen retry hashes remain byte-compatible with the stored completed-run ledger.',
);
$throwsApi(
    static fn () => $legacyZenRetry->assertAcceptedAsNewRun(),
    'A new Zen run cannot add skipped-decoy points.',
);
$throwsApi(
    static fn () => ScoreSubmission::fromArray([
        'runId' => '3e9292a6-5af7-4bed-a948-54a97f53f8ad',
        'mode' => 'zen',
        'score' => 1_000,
        'reactionBasePoints' => 1_000,
        'multiplierBonusPoints' => 0,
        'maxMultiplier' => 1,
        'multiplierHitCounts' => ['one' => 1, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
        'multiplierBasePoints' => ['one' => 1_000, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
        'hits' => 1,
        'dodges' => 0,
        'survivalMs' => 60_000,
        'fastestReactionMs' => 150,
        'averageReactionMs' => 150,
        'speedRatings' => ['godlike' => 1, 'perfect' => 0, 'great' => 0, 'good' => 0],
    ]),
    'Zen submissions must represent a three-minute run.',
);
$throwsApi(
    static fn () => ScoreSubmission::fromArray([
        'runId' => '1ae1e67d-ec40-48e5-863e-f79239cfcb86',
        'mode' => 'normal',
        'score' => 1_000,
        'reactionBasePoints' => 1_000,
        'multiplierBonusPoints' => 0,
        'maxMultiplier' => 1,
        'multiplierHitCounts' => ['one' => 2, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
        'multiplierBasePoints' => ['one' => 1_000, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
        'hits' => 2,
        'dodges' => 0,
        'survivalMs' => 1_000,
        'fastestReactionMs' => 100,
        'averageReactionMs' => 200,
        'speedRatings' => ['godlike' => 1, 'perfect' => 0, 'great' => 0, 'good' => 0],
    ]),
    'Rating counts must cover every hit.',
);
$throwsApi(
    static fn () => ScoreSubmission::fromArray([
        'runId' => 'not-a-uuid',
        'mode' => 'normal',
        'score' => 1_000,
        'reactionBasePoints' => 1_000,
        'multiplierBonusPoints' => 0,
        'maxMultiplier' => 1,
        'multiplierHitCounts' => ['one' => 1, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
        'multiplierBasePoints' => ['one' => 1_000, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
        'hits' => 1,
        'dodges' => 0,
        'survivalMs' => 1_000,
        'fastestReactionMs' => 100,
        'averageReactionMs' => 100,
        'speedRatings' => ['godlike' => 1, 'perfect' => 0, 'great' => 0, 'good' => 0],
    ]),
    'Run submissions require a version-four UUID.',
);
$throwsApi(
    static fn () => ScoreSubmission::fromArray([
        'runId' => '17bfc901-b1b4-461d-9ed8-e2e63d5e18be',
        'mode' => 'normal',
        'score' => 2_000,
        'reactionBasePoints' => 1_000,
        'multiplierBonusPoints' => 500,
        'maxMultiplier' => 1,
        'multiplierHitCounts' => ['one' => 1, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
        'multiplierBasePoints' => ['one' => 1_000, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
        'hits' => 1,
        'dodges' => 0,
        'survivalMs' => 1_000,
        'fastestReactionMs' => 100,
        'averageReactionMs' => 100,
        'speedRatings' => ['godlike' => 1, 'perfect' => 0, 'great' => 0, 'good' => 0],
    ]),
    'Multiplier bonus points cannot exceed the submitted multiplier.',
);
$throwsApi(
    static fn () => ScoreSubmission::fromArray([
        'runId' => '7fef50a2-8a53-4fd8-92ca-8dcc1227b075',
        'mode' => 'normal',
        'score' => 6_000,
        'reactionBasePoints' => 6_000,
        'multiplierBonusPoints' => 0,
        'maxMultiplier' => 2,
        'multiplierHitCounts' => ['one' => 5, 'two' => 1, 'three' => 0, 'four' => 0, 'five' => 0],
        'multiplierBasePoints' => ['one' => 5_000, 'two' => 1_000, 'three' => 0, 'four' => 0, 'five' => 0],
        'hits' => 6,
        'dodges' => 0,
        'survivalMs' => 1_000,
        'fastestReactionMs' => 100,
        'averageReactionMs' => 100,
        'speedRatings' => ['godlike' => 1, 'perfect' => 0, 'great' => 0, 'good' => 5],
    ]),
    'Maximum multiplier cannot exceed the milestone allowed by qualifying streak steps.',
);
$throwsApi(
    static fn () => ScoreSubmission::fromArray([
        'runId' => '557aa694-d5db-44e6-9d38-b4ce0cdd0461',
        'mode' => 'normal',
        'score' => 7_000,
        'reactionBasePoints' => 6_000,
        'multiplierBonusPoints' => 1_000,
        'maxMultiplier' => 2,
        'multiplierHitCounts' => ['one' => 5, 'two' => 1, 'three' => 0, 'four' => 0, 'five' => 0],
        'multiplierBasePoints' => ['one' => 5_000, 'two' => 1_000, 'three' => 0, 'four' => 0, 'five' => 0],
        'hits' => 6,
        'dodges' => 0,
        'survivalMs' => 10_000,
        'fastestReactionMs' => 350,
        'averageReactionMs' => 367,
        'speedRatings' => ['godlike' => 0, 'perfect' => 0, 'great' => 5, 'good' => 1],
    ]),
    'Great reactions cannot unlock a multiplier.',
);
$godlikeUnlockedGoodAtMultiplierRun = ScoreSubmission::fromArray([
    'runId' => '557aa694-d5db-44e6-9d38-b4ce0cdd0462',
    'mode' => 'normal',
    'score' => 5_000,
    'reactionBasePoints' => 4_000,
    'multiplierBonusPoints' => 1_000,
    'maxMultiplier' => 2,
    'multiplierHitCounts' => ['one' => 3, 'two' => 1, 'three' => 0, 'four' => 0, 'five' => 0],
    'multiplierBasePoints' => ['one' => 3_000, 'two' => 1_000, 'three' => 0, 'four' => 0, 'five' => 0],
    'hits' => 4,
    'dodges' => 0,
    'survivalMs' => 10_000,
    'fastestReactionMs' => 150,
    'averageReactionMs' => 225,
    'speedRatings' => ['godlike' => 3, 'perfect' => 0, 'great' => 0, 'good' => 1],
]);
$assert(
    $godlikeUnlockedGoodAtMultiplierRun->godlikeCount === 3
        && $godlikeUnlockedGoodAtMultiplierRun->goodCount === 1
        && $godlikeUnlockedGoodAtMultiplierRun->multiplierTwoHits === 1
        && $godlikeUnlockedGoodAtMultiplierRun->multiplierBonusPoints === 1_000,
    'Three Godlike reactions unlock a multiplier that a later Good reaction can preserve and use.',
);
$perfectUnlockedGoodAtMultiplierRun = ScoreSubmission::fromArray([
    'runId' => '557aa694-d5db-44e6-9d38-b4ce0cdd0463',
    'mode' => 'normal',
    'score' => 7_000,
    'reactionBasePoints' => 6_000,
    'multiplierBonusPoints' => 1_000,
    'maxMultiplier' => 2,
    'multiplierHitCounts' => ['one' => 5, 'two' => 1, 'three' => 0, 'four' => 0, 'five' => 0],
    'multiplierBasePoints' => ['one' => 5_000, 'two' => 1_000, 'three' => 0, 'four' => 0, 'five' => 0],
    'hits' => 6,
    'dodges' => 0,
    'survivalMs' => 10_000,
    'fastestReactionMs' => 250,
    'averageReactionMs' => 283,
    'speedRatings' => ['godlike' => 0, 'perfect' => 5, 'great' => 0, 'good' => 1],
]);
$assert(
    $perfectUnlockedGoodAtMultiplierRun->perfectCount === 5
        && $perfectUnlockedGoodAtMultiplierRun->multiplierTwoHits === 1,
    'Five Perfect reactions unlock the same next-tap multiplier.',
);
$throwsApi(
    static fn () => ScoreSubmission::fromArray([
        'runId' => '557aa694-d5db-44e6-9d38-b4ce0cdd0464',
        'mode' => 'normal',
        'score' => 4_000,
        'reactionBasePoints' => 3_000,
        'multiplierBonusPoints' => 1_000,
        'maxMultiplier' => 2,
        'multiplierHitCounts' => ['one' => 2, 'two' => 1, 'three' => 0, 'four' => 0, 'five' => 0],
        'multiplierBasePoints' => ['one' => 2_000, 'two' => 1_000, 'three' => 0, 'four' => 0, 'five' => 0],
        'hits' => 3,
        'dodges' => 0,
        'survivalMs' => 10_000,
        'fastestReactionMs' => 150,
        'averageReactionMs' => 150,
        'speedRatings' => ['godlike' => 3, 'perfect' => 0, 'great' => 0, 'good' => 0],
    ]),
    'The first multiplier tier needs at least three hits even with Godlike reactions.',
);
$throwsApi(
    static fn () => ScoreSubmission::fromArray([
        'runId' => '557aa694-d5db-44e6-9d38-b4ce0cdd0465',
        'mode' => 'normal',
        'score' => 8_000,
        'reactionBasePoints' => 5_000,
        'multiplierBonusPoints' => 3_000,
        'maxMultiplier' => 3,
        'multiplierHitCounts' => ['one' => 3, 'two' => 1, 'three' => 1, 'four' => 0, 'five' => 0],
        'multiplierBasePoints' => ['one' => 3_000, 'two' => 1_000, 'three' => 1_000, 'four' => 0, 'five' => 0],
        'hits' => 5,
        'dodges' => 0,
        'survivalMs' => 10_000,
        'fastestReactionMs' => 150,
        'averageReactionMs' => 150,
        'speedRatings' => ['godlike' => 5, 'perfect' => 0, 'great' => 0, 'good' => 0],
    ]),
    'A carried-over tier still needs at least two hits before the next multiplier is used.',
);

$maxMultiplierRun = ScoreSubmission::fromArray([
    'runId' => 'cc2dc024-3300-4cb8-9d3c-e7f68eb8963c',
    'mode' => 'normal',
    'score' => 29_000,
    'reactionBasePoints' => 11_000,
    'multiplierBonusPoints' => 18_000,
    'maxMultiplier' => 5,
    'multiplierHitCounts' => ['one' => 3, 'two' => 2, 'three' => 3, 'four' => 2, 'five' => 1],
    'multiplierBasePoints' => ['one' => 3_000, 'two' => 2_000, 'three' => 3_000, 'four' => 2_000, 'five' => 1_000],
    'hits' => 11,
    'dodges' => 0,
    'survivalMs' => 120_000,
    'fastestReactionMs' => 100,
    'averageReactionMs' => 100,
    'speedRatings' => ['godlike' => 11, 'perfect' => 0, 'great' => 0, 'good' => 0],
]);
$assert($maxMultiplierRun->maxMultiplier === 5, 'Validated scoring supports the five-times multiplier cap.');

$baseOnlySameDuration = ScoreSubmission::fromArray([
    'runId' => 'd4d867d5-4077-45dd-8428-8b652fcf1299',
    'mode' => 'normal',
    'score' => 21_000,
    'reactionBasePoints' => 21_000,
    'multiplierBonusPoints' => 0,
    'maxMultiplier' => 1,
    'multiplierHitCounts' => ['one' => 21, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
    'multiplierBasePoints' => ['one' => 21_000, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
    'hits' => 21,
    'dodges' => 0,
    'survivalMs' => 120_000,
    'fastestReactionMs' => 400,
    'averageReactionMs' => 400,
    'speedRatings' => ['godlike' => 0, 'perfect' => 0, 'great' => 0, 'good' => 21],
]);
$multipliedRunCoins = CoinProgression::accrue(0, $maxMultiplierRun->survivalMs);
$baseOnlyRunCoins = CoinProgression::accrue(0, $baseOnlySameDuration->survivalMs);
$assert(
    $multipliedRunCoins == $baseOnlyRunCoins,
    'Coin awards depend only on completed play time, not score or multiplier tiers.',
);

$milestoneRuns = [
    3 => [
        'runId' => '5e4f46d1-a132-4b97-b9a1-481090dca940',
        'maxMultiplier' => 1,
        'score' => 3_000,
        'bonus' => 0,
        'counts' => ['one' => 3, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
        'bases' => ['one' => 3_000, 'two' => 0, 'three' => 0, 'four' => 0, 'five' => 0],
    ],
    5 => [
        'runId' => 'f9be7c57-cfbc-494c-8c22-df75889b2bd8',
        'maxMultiplier' => 2,
        'score' => 7_000,
        'bonus' => 2_000,
        'counts' => ['one' => 3, 'two' => 2, 'three' => 0, 'four' => 0, 'five' => 0],
        'bases' => ['one' => 3_000, 'two' => 2_000, 'three' => 0, 'four' => 0, 'five' => 0],
    ],
    8 => [
        'runId' => 'f425c7e3-fc47-401a-9bcc-c89b951fe17b',
        'maxMultiplier' => 3,
        'score' => 16_000,
        'bonus' => 8_000,
        'counts' => ['one' => 3, 'two' => 2, 'three' => 3, 'four' => 0, 'five' => 0],
        'bases' => ['one' => 3_000, 'two' => 2_000, 'three' => 3_000, 'four' => 0, 'five' => 0],
    ],
    10 => [
        'runId' => 'a4249615-4e43-4de1-b704-87d61647d7d7',
        'maxMultiplier' => 4,
        'score' => 24_000,
        'bonus' => 14_000,
        'counts' => ['one' => 3, 'two' => 2, 'three' => 3, 'four' => 2, 'five' => 0],
        'bases' => ['one' => 3_000, 'two' => 2_000, 'three' => 3_000, 'four' => 2_000, 'five' => 0],
    ],
];
foreach ($milestoneRuns as $hitCount => $milestone) {
    $run = ScoreSubmission::fromArray([
        'runId' => $milestone['runId'],
        'mode' => 'normal',
        'score' => $milestone['score'],
        'reactionBasePoints' => $hitCount * 1_000,
        'multiplierBonusPoints' => $milestone['bonus'],
        'maxMultiplier' => $milestone['maxMultiplier'],
        'multiplierHitCounts' => $milestone['counts'],
        'multiplierBasePoints' => $milestone['bases'],
        'hits' => $hitCount,
        'dodges' => 0,
        'survivalMs' => 120_000,
        'fastestReactionMs' => 100,
        'averageReactionMs' => 100,
        'speedRatings' => ['godlike' => $hitCount, 'perfect' => 0, 'great' => 0, 'good' => 0],
    ]);
    $assert(
        $run->maxMultiplier === $milestone['maxMultiplier'],
        'A run ending on qualifying hit ' . $hitCount . ' reports the highest multiplier used, not the next unlocked tier.',
    );
}

$firstHalfMinute = CoinProgression::accrue(0, 30_000);
$secondHalfMinute = CoinProgression::accrue($firstHalfMinute->remainderMs, 30_000);
$assert($firstHalfMinute->coinsEarned === 0, 'An incomplete cumulative minute does not award a coin yet.');
$assert(
    $secondHalfMinute->coinsEarned === 1 && $secondHalfMinute->remainderMs === 0,
    'Partial run time carries into the next accepted run.',
);
$almostOneMinute = CoinProgression::accrue(0, 59_999);
$oneMinute = CoinProgression::accrue($almostOneMinute->remainderMs, 1);
$assert($oneMinute->coinsEarned === 1 && $oneMinute->remainderMs === 0, 'Coin accrual has an exact minute boundary.');
$zenCoins = CoinProgression::accrue(0, ScoreSubmission::ZEN_DURATION_MS);
$assert($zenCoins->coinsEarned === 3 && $zenCoins->remainderMs === 0, 'A complete Zen run awards three coins.');

$achievementDefinitions = AchievementCatalog::all();
$assert(
    array_column($achievementDefinitions, 'id') === [
        'complete_zen',
        'complete_arcade',
        'godlike_speed',
        'collect_5_coins',
        'score_over_100k',
        'buy_a_pet',
    ],
    'The first six achievements have stable server-owned IDs and order.',
);
$assert(
    array_column($achievementDefinitions, 'rewardCoins') === [1, 1, 1, 5, 5, 10],
    'Achievement coin rewards match the accepted catalog.',
);
$assert(
    AchievementCatalog::require('complete_arcade')['title'] === 'Complete Arcade mode',
    'The player-facing Arcade achievement retains the normal-mode storage alias.',
);
$throwsApi(
    static fn () => AchievementCatalog::require('invented_achievement'),
    'Unknown achievements cannot choose a client-defined reward.',
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
$assert(array_column($window['rows'], 'rank_position') === [1, 2, 3, 4, 5, 7, 8, 9, 10, 11], 'Top five and player context are combined without filler rows.');
$assert($window['contextRank'] === 9, 'The requested result rank is returned.');
$assert(LeaderboardWindow::topPercent(9, 100) === 9, 'Top percentage is rounded upward.');
$assert(LeaderboardWindow::topPercent(null, 100) === null, 'Unranked players have no top percentage.');

$emptyObject = new HttpRequest('POST', '/api/profile', [], [], '{}');
$assert($emptyObject->json() === [], 'An empty JSON object is accepted as an object.');
$throwsApi(
    static fn () => (new HttpRequest('POST', '/api/profile', [], [], '[]'))->json(),
    'A JSON list is rejected at the API boundary.',
);
$throwsApi(
    static fn () => (new HttpRequest('POST', '/api/profile', [], [
        'HTTP_ORIGIN' => 'http://speedytapper.otcsoft.com',
        'HTTP_HOST' => 'speedytapper.otcsoft.com',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ], '{}'))->guardSameOriginMutation(),
    'A mutation from the wrong origin scheme is rejected.',
);
$throwsApi(
    static fn () => (new HttpRequest('POST', '/api/profile', [], [
        'HTTP_ORIGIN' => 'https://speedytapper.otcsoft.com:8443',
        'HTTP_HOST' => 'speedytapper.otcsoft.com',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ], '{}'))->guardSameOriginMutation(),
    'A mutation from a different origin port is rejected.',
);

$schema = file_get_contents(dirname(__DIR__) . '/server/migrations/001_profiles_and_leaderboard.sql')
    . file_get_contents(dirname(__DIR__) . '/server/migrations/002_add_nickname_confirmation.sql')
    . file_get_contents(dirname(__DIR__) . '/server/migrations/003_completed_runs_and_coins.sql')
    . file_get_contents(dirname(__DIR__) . '/server/migrations/004_clear_leaderboard_for_multiplier_scoring.sql')
    . file_get_contents(dirname(__DIR__) . '/server/migrations/005_allow_multiple_leaderboard_results.sql')
    . file_get_contents(dirname(__DIR__) . '/server/migrations/007_player_achievements.sql');
foreach (['google_subject_hash', 'nickname_confirmed', 'godlike_count', 'perfect_count', 'great_count', 'good_count', 'leaderboard_player_mode_season_unique', 'completed_runs', 'payload_hash', 'coin_time_remainder_ms', 'players_coin_remainder_range', 'total_play_ms', 'total_coins_collected', 'player_achievements', 'achievement_key', 'claimed_at', 'multiplier_5_hits', 'multiplier_5_base_points'] as $needle) {
    $assert(is_string($schema) && str_contains($schema, $needle), 'Schema contains ' . $needle . '.');
}
$assert(
    is_string($schema) && str_contains($schema, 'DELETE FROM leaderboard_entries'),
    'The multiplier-scoring migration clears all existing leaderboard scores.',
);
$multipleResultsMigration = file_get_contents(dirname(__DIR__) . '/server/migrations/005_allow_multiple_leaderboard_results.sql');
$assert(
    is_string($multipleResultsMigration)
    && str_contains($multipleResultsMigration, 'DROP INDEX leaderboard_player_mode_season_unique')
    && str_contains($multipleResultsMigration, 'ADD KEY leaderboard_player_mode_season_index')
    && !str_contains($multipleResultsMigration, 'DELETE FROM'),
    'The multi-result migration replaces only the uniqueness constraint and preserves scores.',
);
$achievementMigration = file_get_contents(dirname(__DIR__) . '/server/migrations/007_player_achievements.sql');
$assert(
    is_string($achievementMigration)
    && str_contains($achievementMigration, "WHERE mode = 'normal'")
    && str_contains($achievementMigration, "WHERE mode = 'zen' AND duration_ms = 180000")
    && str_contains($achievementMigration, 'WHERE godlike_count > 0')
    && str_contains($achievementMigration, 'WHERE score > 100000')
    && str_contains($achievementMigration, 'WHERE total_coins_collected >= 5')
    && str_contains($achievementMigration, "acquisition_source = ''purchase''")
    && !str_contains($achievementMigration, 'legacy_easter_egg'),
    'Achievement backfill uses accepted runs, lifetime coins, strict score threshold, and real pet purchases.',
);

$app = file_get_contents(dirname(__DIR__) . '/server/src/App.php');
foreach (['/api/session', '/api/auth/google', '/api/logout', '/api/profile', '/api/leaderboard', '/api/achievements', '/api/achievements/claim'] as $route) {
    $assert(is_string($app) && str_contains($app, $route), 'API includes ' . $route . '.');
}
$assert(
    is_string($app) && str_contains($app, "profile['nicknameConfirmed']"),
    'Score submission requires a user-confirmed public nickname.',
);
$assert(
    is_string($app) && str_contains($app, '$this->runs->submit'),
    'Accepted scores pass through idempotent run accounting.',
);

$runService = file_get_contents(dirname(__DIR__) . '/server/src/RunSubmissionService.php');
$assert(
    is_string($runService)
    && str_contains($runService, 'FOR UPDATE')
    && str_contains($runService, 'hash_equals')
    && str_contains($runService, 'CoinProgression::accrue')
    && str_contains($runService, '$score->survivalMs')
    && str_contains($runService, 'total_coins_collected')
    && str_contains($runService, 'unlockForRunInTransaction')
    && str_contains($runService, 'insertResultInTransaction')
    && str_contains($runService, 'hasExactSubmittedContext')
    && str_contains($runService, "\$payload['submittedRank'] = \$hasExactSubmittedContext")
    && str_contains($runService, "\$payload['submittedEntryId'] = \$hasExactSubmittedContext"),
    'Run accounting inserts each result and never presents a legacy best row as an exact submitted context.',
);
$assert(
    is_string($runService)
    && strpos($runService, 'findCompletedRun') < strpos($runService, 'assertAcceptedAsNewRun'),
    'Legacy Zen scoring is accepted only after an identical completed run is found.',
);
$achievementService = file_get_contents(dirname(__DIR__) . '/server/src/AchievementService.php');
$assert(
    is_string($achievementService)
    && str_contains($achievementService, 'FOR UPDATE')
    && str_contains($achievementService, 'claimed_at IS NULL')
    && str_contains($achievementService, "\$score->mode === 'zen'")
    && str_contains($achievementService, '$score->godlikeCount > 0')
    && str_contains($achievementService, '$score->score > 100_000')
    && str_contains($achievementService, "acquisition_source = 'purchase'")
    && str_contains($achievementService, 'total_coins_collected')
    && str_contains($achievementService, "'duplicate' => \$duplicate"),
    'Achievements unlock and claim from server evidence with serialized idempotent coin accounting.',
);
$leaderboardRepository = file_get_contents(dirname(__DIR__) . '/server/src/LeaderboardRepository.php');
$assert(
    is_string($leaderboardRepository)
    && str_contains($leaderboardRepository, "\$parameters['id'] = \$score->runId")
    && str_contains($leaderboardRepository, 'INSERT INTO leaderboard_entries')
    && str_contains($leaderboardRepository, 'contextRank')
    && str_contains($leaderboardRepository, 'ORDER BY rank_position ASC LIMIT 1')
    && !str_contains($leaderboardRepository, 'UPDATE leaderboard_entries'),
    'Each accepted run is immutable, context can target that exact result, and profile rank remains the best run.',
);
$playerRepository = file_get_contents(dirname(__DIR__) . '/server/src/PlayerRepository.php');
$assert(
    is_string($playerRepository)
    && str_contains($playerRepository, "'coins' =>")
    && str_contains($playerRepository, "'totalPlayMs' =>"),
    'Profile responses expose the persistent coin balance and accepted play time.',
);

$migrationStatements = MigrationRunner::splitStatements(
    "CREATE TABLE example (id INT);\nINSERT INTO example (id) VALUES (1);\n",
);
$assert(count($migrationStatements) === 2, 'Migration SQL is split into executable statements.');
$assert(str_starts_with($migrationStatements[1], 'INSERT INTO example'), 'Migration statement order is preserved.');

$apiBootstrap = file_get_contents(dirname(__DIR__) . '/api/index.php');
$migrationCli = file_get_contents(dirname(__DIR__) . '/server/bin/migrate.php');
$assert(
    is_string($apiBootstrap)
    && str_contains($apiBootstrap, 'new MigrationRunner')
    && str_contains($apiBootstrap, 'new AchievementService'),
    'The HTTP API applies pending migrations before dispatch.',
);
$assert(
    is_string($migrationCli) && str_contains($migrationCli, 'new MigrationRunner'),
    'The migration CLI uses the shared migration runner.',
);
$migrationRunner = file_get_contents(dirname(__DIR__) . '/server/src/MigrationRunner.php');
$assert(
    is_string($migrationRunner)
    && str_contains($migrationRunner, 'GET_LOCK')
    && str_contains($migrationRunner, 'finally')
    && substr_count($migrationRunner, 'pendingPaths(') >= 2,
    'Automatic migration is serialized and rechecks pending work after the lock.',
);

$configSource = file_get_contents(dirname(__DIR__) . '/server/src/Config.php');
$gitignore = file_get_contents(dirname(__DIR__) . '/.gitignore');
$htaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
$assert(
    is_string($configSource)
    && str_contains($configSource, 'SPEEDYTAPPER_CONFIG_PATH')
    && str_contains($configSource, '/.config/speedytapper/config.php')
    && str_contains($configSource, '/server/config.local.php'),
    'Configuration prefers private paths and retains the ignored artifact/local fallback.',
);
$assert(
    is_string($gitignore) && str_contains($gitignore, 'server/config.local.php'),
    'The artifact/local configuration fallback cannot be committed accidentally.',
);
$assert(
    is_string($htaccess) && str_contains($htaccess, '(?:server|vendor|\.git)'),
    'The production web server denies the configuration and application-internal directories.',
);

fwrite(STDOUT, 'PHP backend tests passed (' . $assertions . ' assertions).' . PHP_EOL);
