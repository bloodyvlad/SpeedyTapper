<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\MultiplayerCatalog;
use SpeedyTapper\MultiplayerProofValidator;
use SpeedyTapper\MultiplayerTranscript;
use SpeedyTapper\RunProof;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$rejects = static function (
    callable $operation,
    string $expectedMessage,
    string $message,
) use ($assert): void {
    try {
        $operation();
    } catch (ApiException $error) {
        $assert(
            str_contains($error->getMessage(), $expectedMessage),
            $message . ' Got: ' . $error->getMessage(),
        );
        return;
    }
    $assert(false, $message);
};

$matchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$participants = [
    [
        'participantId' => '11111111-1111-4111-8111-111111111111',
        'playerId' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'seat' => 0,
        'colorIndex' => 0,
    ],
    [
        'participantId' => '22222222-2222-4222-8222-222222222222',
        'playerId' => 'aaaaaaaa-0000-4000-8000-000000000002',
        'seat' => 1,
        'colorIndex' => 1,
    ],
];

/**
 * Generate one complete match instead of freezing a large opaque fixture.
 * The two late decoys overlap after 70 seconds, and target cells stay in 0–11
 * while decoys reserve 13–15.
 *
 * @return array{
 *   payload: array<string, mixed>,
 *   firstPersistentHitIndex: int,
 *   firstDecoyExpiryIndex: int,
 *   firstMinimumWindowHitIndex: int
 * }
 */
$validFixture = static function () use ($matchId): array {
    $events = [];
    $sequence = 0;
    $scheduledExpiries = [];
    $firstDecoyExpiryIndex = -1;

    $appendDirect = static function (
        int $type,
        int $at,
        array $parts,
    ) use (&$events, &$sequence): int {
        $events[] = [$type, ++$sequence, $at, ...$parts];
        return count($events) - 1;
    };
    $append = static function (
        int $type,
        int $at,
        array $parts,
    ) use (
        &$scheduledExpiries,
        &$firstDecoyExpiryIndex,
        $appendDirect,
    ): int {
        if ($type !== MultiplayerTranscript::EVENT_DECOY_EXPIRE) {
            uasort(
                $scheduledExpiries,
                static fn (array $left, array $right): int =>
                    [$left['at'], $left['id']] <=> [$right['at'], $right['id']],
            );
            foreach ($scheduledExpiries as $key => $expiry) {
                if ($expiry['at'] > $at) {
                    break;
                }
                $index = $appendDirect(
                    MultiplayerTranscript::EVENT_DECOY_EXPIRE,
                    $expiry['at'],
                    [$expiry['id']],
                );
                if ($expiry['id'] === 1) {
                    $firstDecoyExpiryIndex = $index;
                }
                unset($scheduledExpiries[$key]);
            }
        }
        $index = $appendDirect($type, $at, $parts);
        if ($type === MultiplayerTranscript::EVENT_DECOY_ACTIVATE) {
            $scheduledExpiries[] = [
                'id' => $parts[1],
                'at' => $at + $parts[4],
            ];
        }
        return $index;
    };

    $targetId = 1;
    $hits = [0, 0];
    $challengeStartHits = [null, null];
    $sawMinimumWindow = [false, false];
    $firstMinimumWindowHitIndex = -1;

    $append(MultiplayerTranscript::EVENT_TARGET, 5_000, [0, $targetId, 0, 0]);
    $append(MultiplayerTranscript::EVENT_HIT, 5_001, [5_001, 0, $targetId, 0]);
    $hits[0]++;
    $targetId++;

    $append(
        MultiplayerTranscript::EVENT_DECOY_ACTIVATE,
        10_000,
        [0, 1, 15, 2, 3_000],
    );
    $append(MultiplayerTranscript::EVENT_TARGET, 10_001, [1, $targetId, 1, 1]);
    $firstPersistentHitIndex = $append(
        MultiplayerTranscript::EVENT_HIT,
        10_002,
        [10_002, 1, $targetId, 1],
    );
    $hits[1]++;
    $targetId++;

    $targetAt = 13_000;
    $nextSeat = 0;
    $lateOverlapStartedAt = null;
    $secondLateDecoyAdded = false;
    $lastHitAt = 10_002;

    while (!$sawMinimumWindow[0] || !$sawMinimumWindow[1]) {
        $seat = $nextSeat;
        if ($targetAt >= 50_000 && $challengeStartHits[$seat] === null) {
            $challengeStartHits[$seat] = $hits[$seat];
        }
        $challengeHits = $challengeStartHits[$seat] === null
            ? 0
            : $hits[$seat] - $challengeStartHits[$seat];
        $responseWindow = $targetAt < 50_000
            ? ($targetAt < 20_000 ? 1_000
                : ($targetAt < 30_000
                    ? (int) round(1_000 - 250 * (($targetAt - 20_000) / 10_000))
                    : ($targetAt < 40_000 ? 750 : 1_000)))
            : max(200, 1_000 - $challengeHits * 5);
        $reaction = $responseWindow === 200 ? 199 : 1;
        $cell = $targetId % 12;
        $append(
            MultiplayerTranscript::EVENT_TARGET,
            $targetAt,
            [$seat, $targetId, $cell, $seat],
        );
        $hitAt = $targetAt + $reaction;
        $hitIndex = $append(
            MultiplayerTranscript::EVENT_HIT,
            $hitAt,
            [$hitAt, $seat, $targetId, $cell],
        );
        if ($responseWindow === 200) {
            $sawMinimumWindow[$seat] = true;
            if ($firstMinimumWindowHitIndex < 0) {
                $firstMinimumWindowHitIndex = $hitIndex;
            }
        }
        $hits[$seat]++;
        $targetId++;
        $lastHitAt = $hitAt;

        if ($lateOverlapStartedAt === null && $hitAt >= 70_000) {
            $lateOverlapStartedAt = $hitAt;
            $append(
                MultiplayerTranscript::EVENT_DECOY_ACTIVATE,
                $hitAt,
                [1, 2, 14, 3, 3_000],
            );
        } elseif (
            $lateOverlapStartedAt !== null
            && !$secondLateDecoyAdded
            && $hitAt - $lateOverlapStartedAt >= 600
        ) {
            $append(
                MultiplayerTranscript::EVENT_DECOY_ACTIVATE,
                $hitAt,
                [0, 3, 13, 4, 3_000],
            );
            $secondLateDecoyAdded = true;
        }

        $nextSeat = 1 - $seat;
        $targetAt = $hitAt + 250;
    }

    // Flush all independent expiries before life-loss cleanup and elimination.
    $time = $lastHitAt + 5_001;
    for ($life = 1; $life <= 3; $life++) {
        foreach ([0, 1] as $seat) {
            $append(
                MultiplayerTranscript::EVENT_MISS,
                $time,
                [$time, $seat, MultiplayerTranscript::MISS_EMPTY, -1],
            );
            if ($life === 3) {
                $append(
                    MultiplayerTranscript::EVENT_PLAYER_OUT,
                    $time,
                    [$seat],
                );
            }
            $time++;
        }
    }
    $append(MultiplayerTranscript::EVENT_FINISH, $time, []);

    return [
        'payload' => [
            'matchId' => $matchId,
            'buildId' => RunProof::BUILD_ID,
            'ruleset' => MultiplayerCatalog::RULESET_ID,
            'protocolVersion' => MultiplayerCatalog::PROTOCOL_VERSION,
            'proofVersion' => MultiplayerCatalog::PROOF_VERSION,
            'events' => $events,
        ],
        'firstPersistentHitIndex' => $firstPersistentHitIndex,
        'firstDecoyExpiryIndex' => $firstDecoyExpiryIndex,
        'firstMinimumWindowHitIndex' => $firstMinimumWindowHitIndex,
    ];
};

$fixture = $validFixture();
$transcript = MultiplayerTranscript::fromArray($fixture['payload']);
$sameTraceDifferentMatch = MultiplayerTranscript::fromArray([
    ...$fixture['payload'],
    'matchId' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
]);
$assert(
    !hash_equals($transcript->hash(), $sameTraceDifferentMatch->hash()),
    'A full transcript hash remains bound to its match.',
);
$assert(
    hash_equals($transcript->traceHash(), $sameTraceDifferentMatch->traceHash()),
    'Replay detection remains stable when the same event trace is copied to another match.',
);
$validated = (new MultiplayerProofValidator())->validate($transcript, $participants);
$assert(
    $validated['durationMs'] > 70_000 && count($validated['results']) === 2,
    'A full two-player own-color match validates through settlement.',
);
$bySeat = [];
foreach ($validated['results'] as $result) {
    $bySeat[$result['seat']] = $result;
}
$assert(
    $bySeat[0]['dodges'] === 2
        && $bySeat[1]['dodges'] === 1
        && $bySeat[0]['lives'] === 0
        && $bySeat[1]['lives'] === 0,
    'Natural independent expiries award their rotated owner and both player-out transitions settle.',
);
$assert(
    $bySeat[0]['durationMs'] < $bySeat[1]['durationMs']
        && $bySeat[1]['durationMs'] < $validated['durationMs'],
    'Each player result freezes survival time at that player’s elimination.',
);
$assert(
    $fixture['firstPersistentHitIndex'] < $fixture['firstDecoyExpiryIndex']
        && $bySeat[0]['score'] >= 2 * MultiplayerCatalog::DODGE_POINTS,
    'A correct target hit does not clear a still-live decoy or suppress its later dodge.',
);
$assert(
    $fixture['firstMinimumWindowHitIndex'] > 0,
    'The valid fixture reaches the 200-millisecond floor after five-millisecond decrements.',
);

$oldLifetime = $fixture['payload'];
foreach ($oldLifetime['events'] as &$event) {
    if (
        $event[0] === MultiplayerTranscript::EVENT_DECOY_ACTIVATE
        && $event[4] === 1
    ) {
        $event[7] = 999;
        break;
    }
}
unset($event);
$rejects(
    fn () => (new MultiplayerProofValidator())->validate(
        MultiplayerTranscript::fromArray($oldLifetime),
        $participants,
    ),
    'outside 1 to 3 seconds',
    'Legacy sub-second multiplayer decoy lifetimes are rejected.',
);

$targetOverDecoy = $fixture['payload'];
$targetOverDecoy['events'][$fixture['firstPersistentHitIndex'] - 1][5] = 15;
$rejects(
    fn () => (new MultiplayerProofValidator())->validate(
        MultiplayerTranscript::fromArray($targetOverDecoy),
        $participants,
    ),
    'live decoy cell',
    'A correct target cannot replace or overlap a live decoy.',
);

$missingExpiry = $fixture['payload'];
array_splice($missingExpiry['events'], $fixture['firstDecoyExpiryIndex'], 1);
foreach ($missingExpiry['events'] as $index => &$event) {
    $event[1] = $index + 1;
}
unset($event);
$rejects(
    fn () => (new MultiplayerProofValidator())->validate(
        MultiplayerTranscript::fromArray($missingExpiry),
        $participants,
    ),
    'expired decoy is missing',
    'A decoy cannot disappear without its independent expiry transition.',
);

$tooManyEarly = $fixture['payload'];
$firstActivationIndex = null;
foreach ($tooManyEarly['events'] as $index => $event) {
    if (
        $event[0] === MultiplayerTranscript::EVENT_DECOY_ACTIVATE
        && $event[4] === 1
    ) {
        $firstActivationIndex = $index;
        break;
    }
}
$earlyOverlap = [
    MultiplayerTranscript::EVENT_DECOY_ACTIVATE,
    0,
    10_600,
    1,
    2,
    14,
    3,
    2_000,
];
array_splice($tooManyEarly['events'], $firstActivationIndex + 1, 0, [$earlyOverlap]);
foreach ($tooManyEarly['events'] as $index => &$event) {
    $event[1] = $index + 1;
}
unset($event);
$rejects(
    fn () => (new MultiplayerProofValidator())->validate(
        MultiplayerTranscript::fromArray($tooManyEarly),
        $participants,
    ),
    'Too many decoys',
    'Only one decoy may be live before the 70-second multiplayer phase.',
);

$minimumWindowLate = $fixture['payload'];
$hitIndex = $fixture['firstMinimumWindowHitIndex'];
$target = $minimumWindowLate['events'][$hitIndex - 1];
$deadline = $target[2] + 200;
$minimumWindowLate['events'][$hitIndex][2] = $deadline;
$minimumWindowLate['events'][$hitIndex][3] = $deadline;
$rejects(
    fn () => (new MultiplayerProofValidator())->validate(
        MultiplayerTranscript::fromArray($minimumWindowLate),
        $participants,
    ),
    'expired target',
    'The 200-millisecond deadline remains exclusive at the late-game floor.',
);

foreach ([
    ['buildId', '19000101-1', 'game build'],
    ['ruleset', 'wrong-ruleset', 'ruleset'],
    ['protocolVersion', 2, 'protocol version'],
    ['proofVersion', 2, 'proof version'],
] as [$field, $value, $message]) {
    $invalidMetadata = $fixture['payload'];
    $invalidMetadata[$field] = $value;
    $rejects(
        fn () => MultiplayerTranscript::fromArray($invalidMetadata),
        $message,
        'Nonmatching multiplayer transcript ' . $field . ' is rejected.',
    );
}
$unknownField = $fixture['payload'];
$unknownField['score'] = 999_999;
$rejects(
    fn () => MultiplayerTranscript::fromArray($unknownField),
    'unsupported fields',
    'Client-supplied aggregate fields are rejected from multiplayer transcripts.',
);

fwrite(STDOUT, 'multiplayer proof assertions: ' . $assertions . PHP_EOL);
