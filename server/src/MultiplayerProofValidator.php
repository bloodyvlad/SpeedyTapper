<?php

declare(strict_types=1);

namespace SpeedyTapper;

/**
 * Replays the shared own-color transcript and derives every result field.
 *
 * Matching submissions from every authenticated participant prevent a lone
 * coordinator from rewriting a match, but colluding or modified clients can
 * still manufacture a structurally valid transcript. Accepted rows are
 * therefore described as protocol-verified, peer-consistent evidence.
 */
final class MultiplayerProofValidator
{
    private const MAX_HANDLER_LAG_MS = 10_000;
    private const TARGET_DELAY_MIN_MS = 250;
    private const TARGET_DELAY_MAX_MS = 5_000;
    private const LIFE_LOSS_RECOVERY_MS = 1_500;
    private const DECOYS_START_AT_MS = 10_000;
    private const MULTIPLE_DECOYS_START_AT_MS = 70_000;
    private const DECOY_LIFETIME_MIN_MS = 1_000;
    private const DECOY_LIFETIME_MAX_MS = 3_000;
    private const DECOY_MINIMUM_GAP_MS = 600;
    private const EXPIRY_LAG_MAX_MS = 5_000;

    /**
     * @param list<array{
     *   participantId: string,
     *   playerId: string,
     *   seat: int,
     *   colorIndex: int
     * }> $participants
     * @return array{
     *   durationMs: int,
     *   transcriptHash: string,
     *   riskScore: int,
     *   riskFlags: list<string>,
     *   results: list<array<string, int|string|null>>
     * }
     */
    public function validate(
        MultiplayerTranscript $transcript,
        array $participants,
    ): array {
        $players = $this->playersBySeat($participants);
        $seatOrder = array_keys($players);
        sort($seatOrder, SORT_NUMERIC);
        $playerCount = count($seatOrder);
        if (
            $playerCount < MultiplayerCatalog::MIN_PLAYERS
            || $playerCount > MultiplayerCatalog::MAX_PLAYERS
        ) {
            throw new ApiException(400, 'Multiplayer participant count is invalid.');
        }

        $activeTarget = null;
        $activeDecoys = [];
        $occupiedDecoyCells = [];
        $nextTargetId = 1;
        $nextDecoyId = 1;
        $nextTargetSeatIndex = 0;
        $nextDecoySeatIndex = 0;
        $nextTargetEarliestAt = self::TARGET_DELAY_MIN_MS;
        $nextTargetLatestAt = self::TARGET_DELAY_MAX_MS;
        $lastDecoyActivatedAt = null;
        $pendingOut = null;
        $lastLogicalAt = 0;
        $finished = false;
        $finishAt = null;
        $totalHits = 0;

        foreach ($transcript->events as $index => $event) {
            $type = $event[0];
            $sequence = $event[1];
            if ($sequence !== $index + 1) {
                $this->invalid('Event sequence is not contiguous.', $index);
            }
            $logicalAt = $event[2];
            if (
                $logicalAt < $lastLogicalAt
                || $logicalAt < 0
                || $logicalAt > MultiplayerCatalog::MAX_DURATION_MS
            ) {
                $this->invalid('Event time is outside the match timeline.', $index);
            }
            if ($finished) {
                $this->invalid('Transcript continues after match finish.', $index);
            }
            if ($pendingOut !== null && $type !== MultiplayerTranscript::EVENT_PLAYER_OUT) {
                $this->invalid('An eliminated player is missing its out transition.', $index);
            }
            $this->assertExpiredDecoyTransition(
                $activeDecoys,
                $type,
                $event,
                $logicalAt,
                $index,
            );
            $this->assertExpiredTargetTransition(
                $activeTarget,
                $activeDecoys,
                $type,
                $event,
                $logicalAt,
                $index,
            );

            if ($type === MultiplayerTranscript::EVENT_TARGET) {
                [, , $at, $ownerSeat, $targetId, $cell, $colorIndex] = $event;
                if ($activeTarget !== null) {
                    $this->invalid('A target appeared while another target was active.', $index);
                }
                if ($at < $nextTargetEarliestAt || $at > $nextTargetLatestAt) {
                    $this->invalid('Target timing is outside the allowed scheduling window.', $index);
                }
                if ($targetId !== $nextTargetId) {
                    $this->invalid('Target identity is not contiguous.', $index);
                }
                $expectedSeat = $this->nextLivingSeat(
                    $players,
                    $seatOrder,
                    $nextTargetSeatIndex,
                );
                if ($ownerSeat !== $expectedSeat || !$this->isLiving($players, $ownerSeat)) {
                    $this->invalid('Target ownership does not follow the fair seat rotation.', $index);
                }
                $this->assertCell($cell, $index);
                if (isset($occupiedDecoyCells[$cell])) {
                    $this->invalid('A target reused a live decoy cell.', $index);
                }
                if ($colorIndex !== $players[$ownerSeat]['colorIndex']) {
                    $this->invalid('Target color does not match its owner.', $index);
                }
                if ($at >= 50_000 && $players[$ownerSeat]['challengeStartHits'] === null) {
                    $players[$ownerSeat]['challengeStartHits'] = $players[$ownerSeat]['hits'];
                }
                $activeTarget = [
                    'id' => $targetId,
                    'ownerSeat' => $ownerSeat,
                    'cell' => $cell,
                    'shownAt' => $at,
                    'responseWindowMs' => $this->responseWindow(
                        $at,
                        $players[$ownerSeat],
                    ),
                ];
                $nextTargetId++;
                $nextTargetSeatIndex = ($this->seatIndex($seatOrder, $ownerSeat) + 1)
                    % $playerCount;
            } elseif ($type === MultiplayerTranscript::EVENT_HIT) {
                [, , $inputAt, $handledAt, $seat, $targetId, $cell] = $event;
                $this->assertInput($inputAt, $handledAt, $index);
                if (
                    $activeTarget === null
                    || $seat !== $activeTarget['ownerSeat']
                    || $targetId !== $activeTarget['id']
                    || $cell !== $activeTarget['cell']
                    || !$this->isLiving($players, $seat)
                ) {
                    $this->invalid('Correct tap does not match the active owned target.', $index);
                }
                $reactionMs = $inputAt - $activeTarget['shownAt'];
                if (
                    $reactionMs < 0
                    || $reactionMs >= $activeTarget['responseWindowMs']
                ) {
                    $this->invalid('Correct tap is outside the target response window.', $index);
                }
                $base = $this->scoreReaction(
                    $reactionMs,
                    $activeTarget['responseWindowMs'],
                );
                $multiplierUsed = $players[$seat]['multiplier'];
                $points = $base * $multiplierUsed;
                $players[$seat]['score'] += $points;
                $players[$seat]['hits']++;
                $players[$seat]['reactions'][] = $reactionMs;
                $players[$seat]['maxMultiplier'] = max(
                    $players[$seat]['maxMultiplier'],
                    $multiplierUsed,
                );
                $rating = $this->rating($reactionMs);
                $players[$seat]['ratings'][$rating]++;
                $steps = $rating === 'godlike' ? 2 : ($rating === 'perfect' ? 1 : 0);
                if ($steps > 0) {
                    [$players[$seat]['multiplier'], $players[$seat]['streakProgress']] =
                        $this->advanceStreak(
                            $players[$seat]['multiplier'],
                            $players[$seat]['streakProgress'],
                            $steps,
                        );
                }
                $totalHits++;
                $activeTarget = null;
                $nextTargetEarliestAt = $handledAt + self::TARGET_DELAY_MIN_MS;
                $nextTargetLatestAt = $handledAt + self::TARGET_DELAY_MAX_MS;
            } elseif ($type === MultiplayerTranscript::EVENT_MISS) {
                [, , $inputAt, $handledAt, $seat, $reason, $cell] = $event;
                $this->assertInput($inputAt, $handledAt, $index);
                if (!$this->isLiving($players, $seat)) {
                    $this->invalid('An eliminated player produced an input event.', $index);
                }
                if (!in_array(
                    $reason,
                    [
                        MultiplayerTranscript::MISS_EMPTY,
                        MultiplayerTranscript::MISS_WRONG,
                        MultiplayerTranscript::MISS_LATE,
                    ],
                    true,
                )) {
                    $this->invalid('Multiplayer miss reason is invalid.', $index);
                }
                if ($reason === MultiplayerTranscript::MISS_LATE) {
                    if (
                        $activeTarget === null
                        || $seat !== $activeTarget['ownerSeat']
                        || $inputAt < $activeTarget['shownAt'] + $activeTarget['responseWindowMs']
                    ) {
                        $this->invalid('Late miss does not match an expired owned target.', $index);
                    }
                } elseif (
                    $activeTarget !== null
                    && $seat === $activeTarget['ownerSeat']
                    && $cell === $activeTarget['cell']
                    && $inputAt < $activeTarget['shownAt'] + $activeTarget['responseWindowMs']
                ) {
                    $this->invalid('A valid owned-target tap was mislabeled as a miss.', $index);
                }
                if ($cell !== -1) {
                    $this->assertCell($cell, $index);
                }

                $players[$seat]['misses']++;
                $players[$seat]['lives']--;
                $players[$seat]['multiplier'] = 1;
                $players[$seat]['streakProgress'] = 0;
                if ($players[$seat]['lives'] < 0) {
                    $this->invalid('A player lost more than three lives.', $index);
                }
                // A life loss clears visible decoys without dodge credit.
                $activeDecoys = [];
                $occupiedDecoyCells = [];
                if ($activeTarget !== null && $activeTarget['ownerSeat'] === $seat) {
                    $activeTarget = null;
                }
                $nextTargetEarliestAt = $handledAt
                    + self::LIFE_LOSS_RECOVERY_MS
                    + self::TARGET_DELAY_MIN_MS;
                $nextTargetLatestAt = $handledAt
                    + self::LIFE_LOSS_RECOVERY_MS
                    + self::TARGET_DELAY_MAX_MS;
                if ($players[$seat]['lives'] === 0) {
                    $pendingOut = ['seat' => $seat, 'at' => $inputAt];
                }
            } elseif ($type === MultiplayerTranscript::EVENT_DECOY_ACTIVATE) {
                [, , $at, $ownerSeat, $decoyId, $cell, $colorIndex, $lifetimeMs] =
                    $event;
                if ($at < self::DECOYS_START_AT_MS) {
                    $this->invalid('A decoy appeared before the multiplayer decoy phase.', $index);
                }
                if (
                    $lastDecoyActivatedAt !== null
                    && $at - $lastDecoyActivatedAt < self::DECOY_MINIMUM_GAP_MS
                ) {
                    $this->invalid('Decoy activations are too close together.', $index);
                }
                if ($decoyId !== $nextDecoyId) {
                    $this->invalid('Decoy identity is not contiguous.', $index);
                }
                if (
                    $lifetimeMs < self::DECOY_LIFETIME_MIN_MS
                    || $lifetimeMs > self::DECOY_LIFETIME_MAX_MS
                ) {
                    $this->invalid('Decoy lifetime is outside 1 to 3 seconds.', $index);
                }
                $capacity = $at < self::MULTIPLE_DECOYS_START_AT_MS
                    ? 1
                    : min(6, 2 + intdiv($totalHits, 20));
                if (count($activeDecoys) >= $capacity) {
                    $this->invalid('Too many decoys are active for this match phase.', $index);
                }
                $expectedSeat = $this->nextLivingSeat(
                    $players,
                    $seatOrder,
                    $nextDecoySeatIndex,
                );
                if ($ownerSeat !== $expectedSeat) {
                    $this->invalid('Decoy dodge ownership does not follow seat rotation.', $index);
                }
                $this->assertCell($cell, $index);
                if (
                    isset($occupiedDecoyCells[$cell])
                    || ($activeTarget !== null && $activeTarget['cell'] === $cell)
                ) {
                    $this->invalid('Decoy reused an occupied gameplay cell.', $index);
                }
                foreach ($players as $player) {
                    if ($colorIndex === $player['colorIndex']) {
                        $this->invalid('Decoy used an assigned player color.', $index);
                    }
                }
                $activeDecoys[$decoyId] = [
                    'id' => $decoyId,
                    'ownerSeat' => $ownerSeat,
                    'cell' => $cell,
                    'expiresAt' => $at + $lifetimeMs,
                ];
                $occupiedDecoyCells[$cell] = $decoyId;
                $nextDecoyId++;
                $lastDecoyActivatedAt = $at;
                $nextDecoySeatIndex = ($this->seatIndex($seatOrder, $ownerSeat) + 1)
                    % $playerCount;
            } elseif ($type === MultiplayerTranscript::EVENT_DECOY_EXPIRE) {
                [, , $at, $decoyId] = $event;
                $decoy = $activeDecoys[$decoyId] ?? null;
                if (
                    !is_array($decoy)
                    || $at < $decoy['expiresAt']
                    || $at > $decoy['expiresAt'] + self::EXPIRY_LAG_MAX_MS
                ) {
                    $this->invalid('Decoy expiry does not match a live decoy.', $index);
                }
                $ownerSeat = $decoy['ownerSeat'];
                if ($this->isLiving($players, $ownerSeat)) {
                    $players[$ownerSeat]['score'] += MultiplayerCatalog::DODGE_POINTS;
                    $players[$ownerSeat]['dodges']++;
                }
                unset($occupiedDecoyCells[$decoy['cell']], $activeDecoys[$decoyId]);
            } elseif ($type === MultiplayerTranscript::EVENT_PLAYER_OUT) {
                [, , $at, $seat] = $event;
                if (
                    $pendingOut === null
                    || $seat !== $pendingOut['seat']
                    || $at !== $pendingOut['at']
                    || $players[$seat]['lives'] !== 0
                ) {
                    $this->invalid('Player-out transition does not match a third life loss.', $index);
                }
                $players[$seat]['outAt'] = $at;
                $pendingOut = null;
            } elseif ($type === MultiplayerTranscript::EVENT_FINISH) {
                [, , $at] = $event;
                if ($index !== count($transcript->events) - 1) {
                    $this->invalid('Match finish must be the final event.', $index);
                }
                foreach ($players as $player) {
                    if ($player['lives'] !== 0) {
                        $this->invalid('Match ended before every player was out.', $index);
                    }
                }
                $activeTarget = null;
                $activeDecoys = [];
                $finished = true;
                $finishAt = $at;
            } else {
                $this->invalid('Unknown multiplayer event.', $index);
            }
            $lastLogicalAt = $logicalAt;
        }

        if (!$finished || $finishAt === null) {
            throw new ApiException(400, 'Multiplayer transcript has no valid finish event.');
        }
        $results = $this->results($players, $playerCount, $finishAt);
        [$riskScore, $riskFlags] = $this->risk($results);
        foreach ($results as &$result) {
            $result['riskScore'] = $riskScore;
        }
        unset($result);
        return [
            'durationMs' => $finishAt,
            'transcriptHash' => $transcript->hash(),
            'riskScore' => $riskScore,
            'riskFlags' => $riskFlags,
            'results' => $results,
        ];
    }

    /** @param list<array<string, mixed>> $participants */
    private function playersBySeat(array $participants): array
    {
        $players = [];
        $colors = [];
        foreach ($participants as $participant) {
            $seat = $participant['seat'] ?? null;
            $color = $participant['colorIndex'] ?? null;
            $participantId = $participant['participantId'] ?? null;
            $playerId = $participant['playerId'] ?? null;
            if (
                !is_int($seat)
                || $seat < 0
                || $seat >= MultiplayerCatalog::MAX_PLAYERS
                || !is_int($color)
                || $color < 0
                || $color >= MultiplayerCatalog::COLOR_COUNT
                || !is_string($participantId)
                || !Uuid::isValidV4($participantId)
                || !is_string($playerId)
                || !Uuid::isValidV4($playerId)
                || isset($players[$seat])
                || isset($colors[$color])
            ) {
                throw new ApiException(400, 'Multiplayer participant manifest is invalid.');
            }
            $colors[$color] = true;
            $players[$seat] = [
                'participantId' => strtolower($participantId),
                'playerId' => strtolower($playerId),
                'colorIndex' => $color,
                'lives' => MultiplayerCatalog::STARTING_LIVES,
                'score' => 0,
                'hits' => 0,
                'misses' => 0,
                'dodges' => 0,
                'multiplier' => 1,
                'maxMultiplier' => 1,
                'streakProgress' => 0,
                'challengeStartHits' => null,
                'reactions' => [],
                'ratings' => [
                    'godlike' => 0,
                    'perfect' => 0,
                    'great' => 0,
                    'good' => 0,
                ],
                'outAt' => null,
            ];
        }
        ksort($players, SORT_NUMERIC);
        if (array_keys($players) !== range(0, count($players) - 1)) {
            throw new ApiException(400, 'Multiplayer seats must be contiguous.');
        }
        return $players;
    }

    private function responseWindow(int $at, array $player): int
    {
        if ($at < 20_000) return 1_000;
        if ($at < 30_000) {
            return (int) round(1_000 - 250 * (($at - 20_000) / 10_000));
        }
        if ($at < 40_000) return 750;
        if ($at < 50_000) return 1_000;
        $challengeHits = $player['challengeStartHits'] === null
            ? 0
            : max(0, $player['hits'] - $player['challengeStartHits']);
        return max(200, 1_000 - $challengeHits * 5);
    }

    private function scoreReaction(int $reactionMs, int $responseWindowMs): int
    {
        $remaining = min(1, max(0, 1 - $reactionMs / $responseWindowMs));
        return (int) round(
            MultiplayerCatalog::SCORE_FLOOR
            + (MultiplayerCatalog::SCORE_CEILING - MultiplayerCatalog::SCORE_FLOOR)
                * ($remaining ** 2),
        );
    }

    private function rating(int $reactionMs): string
    {
        if ($reactionMs < 250) return 'godlike';
        if ($reactionMs < 350) return 'perfect';
        if ($reactionMs < 450) return 'great';
        return 'good';
    }

    /** @return array{int, int} */
    private function advanceStreak(int $multiplier, int $progress, int $steps): array
    {
        if ($multiplier >= MultiplayerCatalog::MAX_MULTIPLIER) {
            return [
                MultiplayerCatalog::MAX_MULTIPLIER,
                MultiplayerCatalog::STREAK_TARGET,
            ];
        }
        $progress += $steps;
        while (
            $progress >= MultiplayerCatalog::STREAK_TARGET
            && $multiplier < MultiplayerCatalog::MAX_MULTIPLIER
        ) {
            $progress -= MultiplayerCatalog::STREAK_TARGET;
            $multiplier++;
        }
        if ($multiplier >= MultiplayerCatalog::MAX_MULTIPLIER) {
            $progress = MultiplayerCatalog::STREAK_TARGET;
        }
        return [$multiplier, $progress];
    }

    private function assertInput(int $inputAt, int $handledAt, int $index): void
    {
        if (
            $handledAt < $inputAt
            || $handledAt - $inputAt > self::MAX_HANDLER_LAG_MS
            || $handledAt > MultiplayerCatalog::MAX_DURATION_MS
        ) {
            $this->invalid('Input handler timing is invalid.', $index);
        }
    }

    private function assertCell(int $cell, int $index): void
    {
        if ($cell < 0 || $cell >= 16) {
            $this->invalid('Gameplay cell is outside the 4x4 board.', $index);
        }
    }

    private function assertExpiredDecoyTransition(
        array $activeDecoys,
        int $type,
        array $event,
        int $logicalAt,
        int $index,
    ): void {
        $expired = array_values(array_filter(
            $activeDecoys,
            static fn (array $decoy): bool => $decoy['expiresAt'] <= $logicalAt,
        ));
        if ($expired === []) return;
        usort(
            $expired,
            static fn (array $left, array $right): int =>
                [$left['expiresAt'], $left['id']] <=> [$right['expiresAt'], $right['id']],
        );
        if (
            $type !== MultiplayerTranscript::EVENT_DECOY_EXPIRE
            || ($event[3] ?? null) !== $expired[0]['id']
        ) {
            $this->invalid('An independently expired decoy is missing its transition.', $index);
        }
    }

    private function assertExpiredTargetTransition(
        ?array $activeTarget,
        array $activeDecoys,
        int $type,
        array $event,
        int $logicalAt,
        int $index,
    ): void {
        if ($activeTarget === null) return;
        $deadline = $activeTarget['shownAt'] + $activeTarget['responseWindowMs'];
        if ($deadline > $logicalAt) return;

        // Independent decoy expiries at or before this event remain ordered
        // ahead of the target timeout. Once they are drained, the owning
        // player's late-miss transition must be the next event.
        foreach ($activeDecoys as $decoy) {
            if (
                $decoy['expiresAt'] <= $logicalAt
                && $type === MultiplayerTranscript::EVENT_DECOY_EXPIRE
                && ($event[3] ?? null) === $decoy['id']
            ) {
                return;
            }
        }
        if (
            $type !== MultiplayerTranscript::EVENT_MISS
            || ($event[4] ?? null) !== $activeTarget['ownerSeat']
            || ($event[5] ?? null) !== MultiplayerTranscript::MISS_LATE
            || ($event[2] ?? null) < $deadline
        ) {
            $this->invalid('An expired target is missing its late-miss transition.', $index);
        }
    }

    private function nextLivingSeat(
        array $players,
        array $seatOrder,
        int $startIndex,
    ): int {
        $count = count($seatOrder);
        for ($offset = 0; $offset < $count; $offset++) {
            $seat = $seatOrder[($startIndex + $offset) % $count];
            if ($this->isLiving($players, $seat)) return $seat;
        }
        throw new ApiException(400, 'No living multiplayer participant remains.');
    }

    private function seatIndex(array $seatOrder, int $seat): int
    {
        $index = array_search($seat, $seatOrder, true);
        if (!is_int($index)) {
            throw new ApiException(400, 'Multiplayer seat is invalid.');
        }
        return $index;
    }

    private function isLiving(array $players, int $seat): bool
    {
        return isset($players[$seat]) && $players[$seat]['lives'] > 0;
    }

    private function results(array $players, int $playerCount, int $durationMs): array
    {
        $results = [];
        foreach ($players as $seat => $player) {
            $reactions = $player['reactions'];
            $results[] = [
                'participantId' => $player['participantId'],
                'playerId' => $player['playerId'],
                'seat' => $seat,
                'placement' => 0,
                'playerCount' => $playerCount,
                'score' => $player['score'],
                'durationMs' => $player['outAt'] ?? $durationMs,
                'fastestReactionMs' => $reactions === [] ? null : min($reactions),
                'averageReactionMs' => $reactions === []
                    ? null
                    : (int) round(array_sum($reactions) / count($reactions)),
                'hits' => $player['hits'],
                'misses' => $player['misses'],
                'dodges' => $player['dodges'],
                'godlikeCount' => $player['ratings']['godlike'],
                'perfectCount' => $player['ratings']['perfect'],
                'greatCount' => $player['ratings']['great'],
                'goodCount' => $player['ratings']['good'],
                'maxMultiplier' => $player['maxMultiplier'],
                'lives' => $player['lives'],
            ];
        }
        usort($results, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score']
                ?: $right['hits'] <=> $left['hits']
                ?: (($left['averageReactionMs'] ?? PHP_INT_MAX)
                    <=> ($right['averageReactionMs'] ?? PHP_INT_MAX))
                ?: $left['seat'] <=> $right['seat'];
        });
        foreach ($results as $index => &$result) {
            $result['placement'] = $index + 1;
        }
        unset($result);
        return $results;
    }

    /** @return array{int, list<string>} */
    private function risk(array $results): array
    {
        $score = 0;
        $flags = [];
        foreach ($results as $result) {
            if (
                $result['fastestReactionMs'] !== null
                && $result['fastestReactionMs'] < 35
            ) {
                $score = 100;
                $flags[] = 'very_low_reaction_sample';
            }
            if (
                $result['hits'] >= 12
                && $result['averageReactionMs'] !== null
                && $result['averageReactionMs'] < 80
            ) {
                $score = 100;
                $flags[] = 'implausibly_fast_reactions';
            }
        }
        return [$score, array_values(array_unique($flags))];
    }

    private function invalid(string $message, int $index): never
    {
        throw new ApiException(
            400,
            $message . ' (multiplayer event ' . $index . ')',
        );
    }
}
