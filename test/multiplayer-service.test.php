<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\MultiplayerCatalog;
use SpeedyTapper\MultiplayerLeaderboardRepository;
use SpeedyTapper\MultiplayerMatchService;
use SpeedyTapper\MultiplayerProofValidator;
use SpeedyTapper\RunProof;
use SpeedyTapper\Uuid;

require dirname(__DIR__) . '/server/autoload.php';

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(<<<'SQL'
CREATE TABLE seasons (id TEXT PRIMARY KEY, name TEXT NOT NULL);
CREATE TABLE players (
    id TEXT PRIMARY KEY,
    nickname TEXT NOT NULL,
    nickname_confirmed INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE player_pet_selection (
    player_id TEXT PRIMARY KEY,
    pet_id TEXT NULL,
    is_visible INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE player_game_center_bindings (
    player_id TEXT PRIMARY KEY,
    game_player_id_hash BLOB NOT NULL,
    last_verified_at TEXT NOT NULL,
    publication_enabled_at TEXT NULL,
    publication_disabled_at TEXT NULL
);
CREATE TABLE multiplayer_matches (
    id TEXT PRIMARY KEY,
    season_id TEXT NOT NULL,
    created_by_player_id TEXT NULL,
    mode TEXT NOT NULL,
    state TEXT NOT NULL,
    capacity INTEGER NOT NULL,
    player_group INTEGER NOT NULL UNIQUE,
    build_id TEXT NOT NULL,
    ruleset_id TEXT NOT NULL,
    protocol_version INTEGER NOT NULL,
    proof_version INTEGER NOT NULL,
    seed BLOB NOT NULL,
    manifest_hash BLOB NULL,
    roster_hash BLOB NULL,
    coordinator_participant_id TEXT NULL,
    transcript_hash BLOB NULL,
    duration_ms INTEGER NULL,
    risk_score INTEGER NOT NULL DEFAULT 0,
    risk_reasons TEXT NULL,
    review_reason TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    started_at TEXT NULL,
    submission_deadline_at TEXT NULL,
    settled_at TEXT NULL,
    CHECK (capacity BETWEEN 2 AND 4),
    CHECK (
        (
            state IN ('forming', 'cancelled', 'expired')
            AND manifest_hash IS NULL
            AND started_at IS NULL
        )
        OR
        (state <> 'forming' AND manifest_hash IS NOT NULL)
    )
);
CREATE TABLE multiplayer_lobby_creation_events (
    match_id TEXT PRIMARY KEY,
    player_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (match_id) REFERENCES multiplayer_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
);
CREATE TABLE multiplayer_participants (
    match_id TEXT NOT NULL,
    participant_id TEXT NOT NULL UNIQUE,
    player_id TEXT NOT NULL,
    seat INTEGER NOT NULL,
    color_index INTEGER NOT NULL,
    ready INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    joined_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    left_at TEXT NULL,
    PRIMARY KEY (match_id, player_id),
    UNIQUE (match_id, seat),
    CHECK (seat >= 0 AND seat < 4),
    CHECK (color_index >= 0 AND color_index < 6),
    FOREIGN KEY (match_id) REFERENCES multiplayer_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
);
CREATE TABLE multiplayer_roster_confirmations (
    match_id TEXT NOT NULL,
    player_id TEXT NOT NULL,
    roster_hash BLOB NOT NULL,
    coordinator_participant_id TEXT NOT NULL,
    confirmed_at TEXT NOT NULL,
    PRIMARY KEY (match_id, player_id),
    FOREIGN KEY (match_id) REFERENCES multiplayer_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
);
CREATE TABLE multiplayer_submissions (
    id TEXT PRIMARY KEY,
    match_id TEXT NOT NULL,
    player_id TEXT NOT NULL,
    manifest_hash BLOB NOT NULL,
    transcript_hash BLOB NOT NULL,
    event_count INTEGER NOT NULL,
    proof_json BLOB NOT NULL,
    submitted_at TEXT NOT NULL,
    UNIQUE (match_id, player_id),
    FOREIGN KEY (match_id) REFERENCES multiplayer_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
);
CREATE TABLE multiplayer_trace_claims (
    trace_hash BLOB PRIMARY KEY,
    first_match_id TEXT NOT NULL UNIQUE,
    claimed_at TEXT NOT NULL,
    FOREIGN KEY (first_match_id) REFERENCES multiplayer_matches(id) ON DELETE CASCADE
);
CREATE TABLE multiplayer_results (
    id TEXT PRIMARY KEY,
    match_id TEXT NOT NULL,
    season_id TEXT NOT NULL,
    player_id TEXT NOT NULL,
    participant_id TEXT NOT NULL,
    placement INTEGER NOT NULL,
    player_count INTEGER NOT NULL,
    score INTEGER NOT NULL,
    duration_ms INTEGER NOT NULL,
    fastest_reaction_ms INTEGER NULL,
    average_reaction_ms INTEGER NULL,
    correct_taps INTEGER NOT NULL,
    miss_count INTEGER NOT NULL,
    dodge_count INTEGER NOT NULL,
    godlike_count INTEGER NOT NULL,
    perfect_count INTEGER NOT NULL,
    great_count INTEGER NOT NULL,
    good_count INTEGER NOT NULL,
    max_multiplier INTEGER NOT NULL,
    verification_status TEXT NOT NULL,
    verification_method TEXT NOT NULL,
    risk_score INTEGER NOT NULL,
    risk_reasons TEXT NOT NULL,
    achieved_at TEXT NOT NULL,
    UNIQUE (match_id, player_id),
    FOREIGN KEY (match_id) REFERENCES multiplayer_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES multiplayer_participants(participant_id) ON DELETE CASCADE
);
SQL);

$seasonId = 'clean';
$database->prepare('INSERT INTO seasons (id, name) VALUES (?, ?)')->execute([$seasonId, 'Clean']);
$playerOne = Uuid::v4();
$playerTwo = Uuid::v4();
$playerThree = Uuid::v4();
$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
$insertPlayer = $database->prepare(
    'INSERT INTO players (id, nickname, nickname_confirmed) VALUES (?, ?, 1)'
);
$insertBinding = $database->prepare(
    'INSERT INTO player_game_center_bindings '
    . '(player_id, game_player_id_hash, last_verified_at, publication_enabled_at, publication_disabled_at) '
    . 'VALUES (?, ?, ?, ?, NULL)'
);
foreach (
    [
        [$playerOne, 'PlayerOne', 'G:one'],
        [$playerTwo, 'PlayerTwo', 'G:two'],
        [$playerThree, 'PlayerThree', 'G:three'],
    ] as [$playerId, $nickname, $gamePlayerId]
) {
    $insertPlayer->execute([$playerId, $nickname]);
    $insertBinding->execute([
        $playerId,
        hash('sha256', "game_center_game_player\0" . $gamePlayerId, true),
        $now,
        $now,
    ]);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = new MultiplayerMatchService(
    $database,
    $seasonId,
    'Clean',
    new MultiplayerProofValidator(),
);
$created = $service->create(
    $playerOne,
    MultiplayerCatalog::MODE_OWN_COLOR,
    4,
    RunProof::BUILD_ID,
);
$matchId = $created['match']['matchId'];
$assert(Uuid::isValidV4($matchId), 'Create should return a match UUID.');
$assert($created['match']['playerGroup'] > 0, 'Create should return a private GameKit player group.');
$assert(count($created['match']['participants']) === 1, 'Creator should occupy the first seat.');

$joined = $service->join($playerTwo, $matchId);
$assert(count($joined['match']['participants']) === 2, 'A second eligible player should join.');
$service->setReady($playerOne, $matchId, true);
$service->setReady($playerTwo, $matchId, true);
$service->confirmGameKitRoster(
    $playerOne,
    $matchId,
    'G:one',
    ['G:two'],
    'G:one',
);
$confirmed = $service->confirmGameKitRoster(
    $playerTwo,
    $matchId,
    'G:two',
    ['G:one'],
    'G:one',
);
$assert($confirmed['confirmedCount'] === 2, 'Every player should confirm one identical GameKit roster.');

$started = $service->start($playerOne, $matchId);
$manifest = $started['manifest'];
$assert($manifest['startingLives'] === 3, 'The immutable manifest should assign three lives.');
$assert(count($manifest['participants']) === 2, 'The manifest should contain the actual starting roster.');

$events = [
    [0, 1, 250, 0, 1, 0, 0],
    [2, 2, 1250, 1250, 0, 2, 0],
    [0, 3, 3000, 1, 2, 1, 1],
    [2, 4, 4000, 4000, 1, 2, 1],
    [0, 5, 5750, 0, 3, 2, 0],
    [2, 6, 6750, 6750, 0, 2, 2],
    [0, 7, 9500, 1, 4, 3, 1],
    [2, 8, 10500, 10500, 1, 2, 3],
    [0, 9, 13250, 0, 5, 4, 0],
    [3, 10, 13400, 0, 1, 6, 2, 1000],
    [1, 11, 13500, 13500, 0, 5, 4],
    [0, 12, 13750, 1, 6, 5, 1],
    [4, 13, 14400, 1],
    [2, 14, 14750, 14750, 1, 2, 5],
    [5, 15, 14750, 1],
    [0, 16, 16500, 0, 7, 7, 0],
    [2, 17, 17500, 17500, 0, 2, 7],
    [5, 18, 17500, 0],
    [6, 19, 17500],
];
$transcript = [
    'matchId' => $matchId,
    'buildId' => RunProof::BUILD_ID,
    'ruleset' => MultiplayerCatalog::RULESET_ID,
    'protocolVersion' => MultiplayerCatalog::PROTOCOL_VERSION,
    'proofVersion' => MultiplayerCatalog::PROOF_VERSION,
    'events' => $events,
];

// The replay duration cannot outrun the PHP match clock. Move the test start
// into the past without weakening the production check.
$database->prepare(
    'UPDATE multiplayer_matches SET started_at = :started_at WHERE id = :match_id'
)->execute([
    'started_at' => (new DateTimeImmutable('-20 seconds', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s.v'),
    'match_id' => $matchId,
]);

$first = $service->submit(
    $playerOne,
    $matchId,
    $manifest['manifestHash'],
    $transcript,
);
$assert($first['state'] === 'collecting', 'One proof should wait for the other participant.');
$database->prepare(
    'UPDATE multiplayer_matches SET submission_deadline_at = :deadline WHERE id = :match_id'
)->execute([
    'deadline' => (new DateTimeImmutable('-1 second', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s.v'),
    'match_id' => $matchId,
]);
$lateRetry = $service->submit(
    $playerOne,
    $matchId,
    $manifest['manifestHash'],
    $transcript,
);
$assert(
    $lateRetry['duplicate'] === true && $lateRetry['state'] === 'collecting',
    'An exact stored submission retry remains idempotent after the deadline.',
);
$database->prepare(
    'UPDATE multiplayer_matches SET submission_deadline_at = :deadline WHERE id = :match_id'
)->execute([
    'deadline' => (new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s.v'),
    'match_id' => $matchId,
]);
$second = $service->submit(
    $playerTwo,
    $matchId,
    $manifest['manifestHash'],
    $transcript,
);
$assert($second['state'] === 'settled', 'Matching proofs from every participant should settle.');
$assert($second['leaderboardEligible'] === true, 'A clean peer-consistent replay should rank.');
$assert(count($second['results']) === 2, 'Settlement should return both placements.');
$assert($second['results'][0]['place'] === 1, 'Tie-breaking should deterministically crown seat zero.');
$assert(
    $second['results'][0]['hits'] === 1 && $second['results'][0]['dodges'] === 1,
    'A correct target keeps its live decoy until independent expiry awards a dodge.',
);
$survivalByParticipant = array_column(
    $second['results'],
    'survivalMs',
    'participantId',
);
$assert(
    $survivalByParticipant[$manifest['participants'][0]['participantId']] === 17_500
        && $survivalByParticipant[$manifest['participants'][1]['participantId']] === 14_750,
    'Each settlement row reports that participant’s own survival time.',
);

$repository = new MultiplayerLeaderboardRepository($database, $seasonId, 'Clean');
$board = $repository->payload($playerOne);
$assert($board['totalEntries'] === 2, 'Every accepted player result should be immutable leaderboard history.');
$assert($board['playerRank'] === 1, 'The first player should receive the derived rank.');
$assert(count($board['entries']) === 2, 'The board should expose both accepted results.');
$assert(
    !array_key_exists('playerId', $board['entries'][0]),
    'The public leaderboard must not expose internal player UUIDs.',
);

$duplicate = $service->submit(
    $playerOne,
    $matchId,
    $manifest['manifestHash'],
    $transcript,
);
$assert($duplicate['state'] === 'settled', 'A completed match should return stable settlement.');
$leftAfterSettlement = $service->leave($playerOne, $matchId);
$assert(
    $leftAfterSettlement['matchCancelled'] === false
        && $service->settlement($playerTwo, $matchId)['state'] === 'settled',
    'Leaving a finished match must not mutate its immutable settlement.',
);

$leaveCreated = $service->create(
    $playerOne,
    MultiplayerCatalog::MODE_OWN_COLOR,
    4,
    RunProof::BUILD_ID,
);
$leaveMatchId = $leaveCreated['match']['matchId'];
$service->join($playerTwo, $leaveMatchId);
$service->join($playerThree, $leaveMatchId);
$service->leave($playerTwo, $leaveMatchId);
$compacted = $service->show($playerThree, $leaveMatchId)['match']['participants'];
$assert(
    count($compacted) === 2
        && $compacted[0]['seat'] === 0
        && $compacted[1]['seat'] === 1
        && $compacted[1]['colorIndex'] === 1,
    'A forming-lobby departure compacts seats without leaving production bounds.',
);

$cloneCreated = $service->create(
    $playerOne,
    MultiplayerCatalog::MODE_OWN_COLOR,
    2,
    RunProof::BUILD_ID,
);
$cloneMatchId = $cloneCreated['match']['matchId'];
$service->join($playerTwo, $cloneMatchId);
$service->setReady($playerOne, $cloneMatchId, true);
$service->setReady($playerTwo, $cloneMatchId, true);
$service->confirmGameKitRoster(
    $playerOne,
    $cloneMatchId,
    'G:one',
    ['G:two'],
    'G:one',
);
$service->confirmGameKitRoster(
    $playerTwo,
    $cloneMatchId,
    'G:two',
    ['G:one'],
    'G:one',
);
$cloneStarted = $service->start($playerOne, $cloneMatchId);
$cloneTranscript = [
    ...$transcript,
    'matchId' => $cloneMatchId,
];
$database->prepare(
    'UPDATE multiplayer_matches SET started_at = :started_at WHERE id = :match_id'
)->execute([
    'started_at' => (new DateTimeImmutable('-20 seconds', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s.v'),
    'match_id' => $cloneMatchId,
]);
$service->submit(
    $playerOne,
    $cloneMatchId,
    $cloneStarted['manifest']['manifestHash'],
    $cloneTranscript,
);
$cloneSettlement = $service->submit(
    $playerTwo,
    $cloneMatchId,
    $cloneStarted['manifest']['manifestHash'],
    $cloneTranscript,
);
$assert(
    $cloneSettlement['state'] === 'review'
        && $cloneSettlement['leaderboardEligible'] === false,
    'Copying the same gameplay trace into a fresh match is quarantined.',
);

$expiredCreated = $service->create(
    $playerOne,
    MultiplayerCatalog::MODE_OWN_COLOR,
    2,
    RunProof::BUILD_ID,
);
$expiredMatchId = $expiredCreated['match']['matchId'];
$database->prepare(
    'UPDATE multiplayer_matches SET expires_at = :expires_at WHERE id = :match_id'
)->execute([
    'expires_at' => (new DateTimeImmutable('-1 second', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s.v'),
    'match_id' => $expiredMatchId,
]);
try {
    $service->setReady($playerOne, $expiredMatchId, true);
    throw new RuntimeException('An expired forming lobby must reject readiness changes.');
} catch (ApiException $error) {
    $assert($error->status === 409, 'An expired lobby should return a conflict.');
}
$assert(
    $database->query(
        "SELECT state FROM multiplayer_matches WHERE id = '{$expiredMatchId}'"
    )->fetchColumn() === 'expired',
    'A direct expired-lobby mutation persists the expired state.',
);

$cancelCreated = $service->create(
    $playerOne,
    MultiplayerCatalog::MODE_OWN_COLOR,
    2,
    RunProof::BUILD_ID,
);
$cancelMatchId = $cancelCreated['match']['matchId'];
$service->join($playerTwo, $cancelMatchId);
$service->setReady($playerOne, $cancelMatchId, true);
$service->setReady($playerTwo, $cancelMatchId, true);
$service->confirmGameKitRoster(
    $playerOne,
    $cancelMatchId,
    'G:one',
    ['G:two'],
    'G:one',
);
$service->confirmGameKitRoster(
    $playerTwo,
    $cancelMatchId,
    'G:two',
    ['G:one'],
    'G:one',
);
$service->start($playerOne, $cancelMatchId);
$cancelled = $service->leave($playerTwo, $cancelMatchId);
$cancelMarkers = $database->query(
    "SELECT state, manifest_hash, started_at, submission_deadline_at "
        . "FROM multiplayer_matches WHERE id = '{$cancelMatchId}'"
)->fetch();
$assert(
    $cancelled['matchCancelled'] === true
        && $cancelMarkers['state'] === 'cancelled'
        && $cancelMarkers['manifest_hash'] === null
        && $cancelMarkers['started_at'] === null
        && $cancelMarkers['submission_deadline_at'] === null,
    'Leaving an active match cancels it without retaining live proof markers.',
);

$outsider = Uuid::v4();
$insertPlayer->execute([$outsider, 'Outsider']);
$insertBinding->execute([
    $outsider,
    hash('sha256', "game_center_game_player\0G:outsider", true),
    $now,
    $now,
]);
try {
    $service->show($outsider, $matchId);
    throw new RuntimeException('Non-members must not read a private match.');
} catch (ApiException $error) {
    $assert($error->status === 404, 'Private match IDOR should return not found.');
}

echo "multiplayer service assertions: {$assertions}\n";
