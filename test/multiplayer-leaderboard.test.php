<?php

declare(strict_types=1);

use SpeedyTapper\MultiplayerLeaderboardRepository;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$database = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$database->exec(
    'CREATE TABLE players ('
    . 'id TEXT PRIMARY KEY, nickname TEXT NOT NULL, nickname_confirmed INTEGER NOT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE player_pet_selection ('
    . 'player_id TEXT PRIMARY KEY, pet_id TEXT NULL, is_visible INTEGER NOT NULL'
    . ')'
);
$database->exec(
    'CREATE TABLE multiplayer_results ('
    . 'id TEXT PRIMARY KEY, season_id TEXT NOT NULL, player_id TEXT NOT NULL, '
    . 'placement INTEGER NOT NULL, player_count INTEGER NOT NULL, score INTEGER NOT NULL, '
    . 'duration_ms INTEGER NOT NULL, fastest_reaction_ms INTEGER NULL, '
    . 'average_reaction_ms INTEGER NULL, correct_taps INTEGER NOT NULL, '
    . 'miss_count INTEGER NOT NULL, dodge_count INTEGER NOT NULL, '
    . 'godlike_count INTEGER NOT NULL, perfect_count INTEGER NOT NULL, '
    . 'great_count INTEGER NOT NULL, good_count INTEGER NOT NULL, '
    . 'max_multiplier INTEGER NOT NULL, verification_status TEXT NOT NULL, '
    . 'achieved_at TEXT NOT NULL'
    . ')'
);

$playerIds = [];
$insertPlayer = $database->prepare(
    'INSERT INTO players (id, nickname, nickname_confirmed) '
    . 'VALUES (:id, :nickname, :confirmed)'
);
for ($index = 1; $index <= 10; $index++) {
    $id = sprintf('00000000-0000-4000-8000-%012d', $index);
    $playerIds[$index] = $id;
    $insertPlayer->execute([
        'id' => $id,
        'nickname' => $index === 1 ? 'bloodyvlad' : 'Player' . $index,
        'confirmed' => 1,
    ]);
}
$database->prepare(
    'INSERT INTO player_pet_selection (player_id, pet_id, is_visible) VALUES (?, ?, 1)'
)->execute([$playerIds[1], 'foka']);
$database->prepare(
    'INSERT INTO player_pet_selection (player_id, pet_id, is_visible) VALUES (?, ?, 1)'
)->execute([$playerIds[8], 'tauta']);

$insertResult = $database->prepare(
    'INSERT INTO multiplayer_results '
    . '(id, season_id, player_id, placement, player_count, score, duration_ms, '
    . 'fastest_reaction_ms, average_reaction_ms, correct_taps, miss_count, dodge_count, '
    . 'godlike_count, perfect_count, great_count, good_count, max_multiplier, '
    . 'verification_status, achieved_at) VALUES '
    . '(:id, :season_id, :player_id, :placement, 4, :score, :duration_ms, '
    . ':fastest, :average, :hits, :misses, :dodges, :godlike, :perfect, :great, '
    . ':good, :max_multiplier, :status, :achieved_at)'
);
$addResult = static function (
    string $id,
    string $playerId,
    int $score,
    int $placement,
    int $duration,
    int $hits,
    string $status = 'verified',
) use ($insertResult): void {
    $insertResult->execute([
        'id' => $id,
        'season_id' => 'season-1',
        'player_id' => $playerId,
        'placement' => $placement,
        'score' => $score,
        'duration_ms' => $duration,
        'fastest' => 201,
        'average' => 352,
        'hits' => $hits,
        'misses' => 3,
        'dodges' => 7,
        'godlike' => 1,
        'perfect' => 2,
        'great' => 3,
        'good' => $hits - 6,
        'max_multiplier' => 3,
        'status' => $status,
        'achieved_at' => '2026-07-29 12:00:' . str_pad(
            (string) (int) substr($id, 1),
            2,
            '0',
            STR_PAD_LEFT,
        ),
    ]);
};

$addResult('r01', $playerIds[1], 1_000, 1, 100_000, 30);
$addResult('r02', $playerIds[2], 900, 2, 100_000, 30);
$addResult('r03', $playerIds[3], 900, 1, 100_000, 30);
$addResult('r04', $playerIds[4], 800, 1, 100_000, 30);
$addResult('r05', $playerIds[5], 800, 1, 200_000, 30);
$addResult('r06', $playerIds[6], 700, 1, 100_000, 10);
$addResult('r07', $playerIds[7], 700, 1, 100_000, 20);
$addResult('r08', $playerIds[8], 600, 1, 100_000, 30);
$addResult('r09', $playerIds[9], 500, 1, 100_000, 30);
$addResult('r10', $playerIds[10], 400, 1, 100_000, 30);
$addResult('r11', $playerIds[8], 50, 4, 10_000, 6);
$addResult('r12', $playerIds[8], 9_999, 1, 1_000, 6, 'review');

$repository = new MultiplayerLeaderboardRepository(
    $database,
    'season-1',
    'Season 1',
);

$public = $repository->topPayload();
$assert($public['mode'] === 'multiplayer', 'The payload identifies the multiplayer board.');
$assert($public['totalEntries'] === 11, 'Only verified immutable results are counted.');
$assert(
    array_column($public['entries'], 'rank') === [1, 2, 3, 4, 5],
    'Signed-out reads expose exactly the public top five.',
);
$assert(
    array_column($public['entries'], 'name') === [
        'bloodyvlad',
        'Player3',
        'Player2',
        'Player5',
        'Player4',
    ],
    'Placement and duration tie-breaks are applied in the documented order.',
);
$assert(
    $public['entries'][0]['petId'] === 'muse',
    'A confirmed nickname easter egg replaces the durable pet in public rows.',
);
$assert(
    $public['playerRank'] === null && $public['topPercent'] === null,
    'Signed-out reads contain no personalized placement.',
);
$assert(
    $public['entries'][0]['verification'] === 'peer_consistent_v1',
    'Public rows describe the limited peer-consistent verification method.',
);

$personalized = $repository->payload($playerIds[8]);
$assert($personalized['playerRank'] === 8, 'The player rank uses their best result.');
$assert(
    $personalized['topPercent'] === 73,
    'The player top percentage is based on every verified result.',
);
$assert(
    array_column($personalized['entries'], 'rank') === [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
    'The personalized window adds two neighbors on either side without duplicating the top five.',
);
$currentRows = array_values(array_filter(
    $personalized['entries'],
    static fn (array $entry): bool => $entry['isCurrentPlayer'],
));
$assert(
    count($currentRows) === 1
        && $currentRows[0]['rank'] === 8
        && $currentRows[0]['petId'] === 'tauta',
    'Only the visible best player result in the context window is marked current.',
);
$assert(
    $currentRows[0]['speedRatings'] === [
        'godlike' => 1,
        'perfect' => 2,
        'great' => 3,
        'good' => 24,
    ]
        && $currentRows[0]['hits'] === 30
        && $currentRows[0]['misses'] === 3
        && $currentRows[0]['dodges'] === 7
        && $currentRows[0]['maxMultiplier'] === 3,
    'Public rows include the server-derived multiplayer statistics.',
);
foreach ($personalized['entries'] as $entry) {
    $assert(
        array_intersect(
            ['id', 'resultId', 'matchId', 'playerId', 'participantId'],
            array_keys($entry),
        ) === [],
        'Public rows do not leak internal identifiers.',
    );
}
$assert(
    $repository->topScoreForPlayer($playerIds[8]) === 600,
    'Game Center authority reads the player verified personal best.',
);
$assert(
    $repository->topScoreForPlayer($playerIds[9]) === 500,
    'Game Center authority remains independent per player.',
);

$missingPlayer = '00000000-0000-4000-8000-000000000099';
$assert(
    $repository->topScoreForPlayer($missingPlayer) === null,
    'Players without a verified multiplayer result have no publishable score.',
);

fwrite(
    STDOUT,
    'multiplayer leaderboard assertions: ' . $assertions . PHP_EOL,
);
