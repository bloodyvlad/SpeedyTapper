<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\LeaderboardWindow;
use SpeedyTapper\Nickname;
use SpeedyTapper\ScoreSubmission;
use SpeedyTapper\Uuid;
use SpeedyTapper\HttpRequest;

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

$assert(Nickname::normalize("  Speedy\n  Player  ") === 'Speedy Player', 'Nickname whitespace is normalized.');
$throwsApi(static fn () => Nickname::normalize(str_repeat('x', 21)), 'Long nicknames are rejected.');
$assert((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Uuid::v4()), 'UUIDs are RFC 4122 version 4.');

$valid = ScoreSubmission::fromArray([
    'mode' => 'normal',
    'score' => 4_550,
    'hits' => 4,
    'dodges' => 1,
    'survivalMs' => 91_000,
    'fastestReactionMs' => 178,
    'averageReactionMs' => 331,
    'speedRatings' => ['godlike' => 1, 'perfect' => 1, 'great' => 1, 'good' => 1],
]);
$assert($valid->godlikeCount === 1 && $valid->hits === 4, 'Validated speed-rating counts are retained.');
$assert($valid->isBetterThan(['score' => 4_500, 'duration_ms' => 100_000, 'correct_taps' => 8]), 'Score is the first ranking criterion.');
$assert($valid->isBetterThan(['score' => 4_550, 'duration_ms' => 90_000, 'correct_taps' => 5]), 'Normal duration breaks score ties.');
$throwsApi(
    static fn () => ScoreSubmission::fromArray([
        'mode' => 'zen',
        'score' => 1_000,
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
        'mode' => 'normal',
        'score' => 1_000,
        'hits' => 2,
        'dodges' => 0,
        'survivalMs' => 1_000,
        'fastestReactionMs' => 100,
        'averageReactionMs' => 200,
        'speedRatings' => ['godlike' => 1, 'perfect' => 0, 'great' => 0, 'good' => 0],
    ]),
    'Rating counts must cover every hit.',
);

$rows = [];
for ($rank = 1; $rank <= 12; $rank++) {
    $rows[] = ['rank_position' => $rank, 'player_id' => $rank === 9 ? 'target-player' : 'player-' . $rank];
}
$window = LeaderboardWindow::select($rows, 'target-player');
$assert(array_column($window['rows'], 'rank_position') === [1, 2, 3, 4, 5, 7, 8, 9, 10, 11], 'Top five and player context are combined without filler rows.');
$assert($window['playerRank'] === 9, 'Current player rank is returned.');
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

$schema = file_get_contents(dirname(__DIR__) . '/server/migrations/001_profiles_and_leaderboard.sql');
foreach (['google_subject_hash', 'godlike_count', 'perfect_count', 'great_count', 'good_count', 'leaderboard_player_mode_season_unique'] as $needle) {
    $assert(is_string($schema) && str_contains($schema, $needle), 'Schema contains ' . $needle . '.');
}

$app = file_get_contents(dirname(__DIR__) . '/server/src/App.php');
foreach (['/api/session', '/api/auth/google', '/api/logout', '/api/profile', '/api/leaderboard'] as $route) {
    $assert(is_string($app) && str_contains($app, $route), 'API includes ' . $route . '.');
}

fwrite(STDOUT, 'PHP backend tests passed (' . $assertions . ' assertions).' . PHP_EOL);
