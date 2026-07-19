<?php

declare(strict_types=1);

namespace SpeedyTapper;

/**
 * Replays reaction-proof-v2 and derives every persisted score field.
 *
 * This closes the previous one-shot aggregate-forgery path. It is intentionally
 * not described as proof that a human produced the browser events: a modified
 * browser can still automate valid input. High-risk but structurally possible
 * traces are returned with conservative review metadata instead of being
 * silently accepted as ordinary competition results.
 */
final class RunProofValidator
{
    public const BUILD_ID = RunProof::BUILD_ID;
    public const RULESET_ID = RunProof::RULESET;
    public const PROOF_VERSION = RunProof::PROOF_VERSION;

    private const ZEN_DURATION_MS = 180_000;
    private const MAX_NORMAL_DURATION_MS = 24 * 60 * 60 * 1_000;
    private const MAX_HANDLER_LAG_MS = 10_000;
    private const SCHEDULER_LAG_RISK_MS = 250;
    // Main-thread stalls can delay a browser timer even while the page stays
    // visible. Keep a finite ceiling so omitted transitions cannot mint idle
    // play time, but leave enough headroom for a temporarily busy phone.
    private const MAX_TRANSITION_LAG_MS = 5_000;
    private const TIMESTAMP_QUANTIZATION_TOLERANCE_MS = 1;

    private const STARTING_LIVES = 3;
    private const LIFE_LOSS_RECOVERY_MS = 1_500;
    private const TWO_BY_TWO_STARTS_AT_HITS = 4;
    private const COLOR_PATIENCE_STARTS_AT_MS = 10_000;
    private const GENTLE_RAMP_STARTS_AT_MS = 20_000;
    private const RARE_DECOYS_STARTS_AT_MS = 30_000;
    private const FOUR_BY_FOUR_STARTS_AT_MS = 40_000;
    private const FOUR_BY_FOUR_CHALLENGE_STARTS_AT_MS = 50_000;

    private const DECOY_LIFETIME_MIN_MS = 450;
    private const DECOY_LIFETIME_MAX_MS = 750;
    private const DECOY_RETRY_MS = 150;
    private const DODGE_POINTS = 550;

    private const SCORE_FLOOR = 100;
    private const SCORE_CEILING = 1_000;
    private const STREAK_TARGET = 5;
    private const MAX_MULTIPLIER = 5;
    private const ZEN_INITIAL_TARGET_DELAY_MS = 1_000;
    private const ZEN_CADENCE_ADAPTATION = 0.5;

    public function validate(RunProof $proof): ScoreSubmission
    {
        if (
            !RunProof::isSupportedBuildId($proof->buildId)
            || $proof->ruleset !== RunProof::RULESET
            || $proof->proofVersion !== RunProof::PROOF_VERSION
        ) {
            throw new ApiException(400, 'Run proof metadata is invalid.');
        }

        $mode = $proof->mode;
        $state = 'waiting';
        $gameOver = false;
        $finished = false;
        $lastHandledAt = 0;

        $hits = 0;
        $misses = 0;
        $lives = self::STARTING_LIVES;
        $dodges = 0;
        $score = 0;
        $reactionBasePoints = 0;
        $multiplierBonusPoints = 0;
        $multiplier = 1;
        $maximumMultiplierUsed = 1;
        $streakProgress = 0;
        $multiplierHits = array_fill(1, self::MAX_MULTIPLIER, 0);
        $multiplierBases = array_fill(1, self::MAX_MULTIPLIER, 0);
        $ratings = ['godlike' => 0, 'perfect' => 0, 'great' => 0, 'good' => 0];
        $reactions = [];
        $handlerLags = [];

        $challengeStartHits = null;
        $targetAt = null;
        $targetCell = null;
        $targetDifficulty = null;
        $zenTargetDelayMs = self::ZEN_INITIAL_TARGET_DELAY_MS;
        $recentlyExpiredCells = [];
        $activeDecoys = [];
        $nextDecoyId = 1;
        $decoySchedule = [
            'minimum' => self::COLOR_PATIENCE_STARTS_AT_MS,
            'maximum' => self::COLOR_PATIENCE_STARTS_AT_MS,
        ];
        $decoyLifetimes = [];
        $decoyCadenceFractions = [];
        $decoyScheduleSamples = 0;
        $missingDecoyTransitions = 0;
        $lateDecoySchedules = 0;

        $targetSchedule = $this->targetSchedule(
            0,
            0,
            $hits,
            $challengeStartHits,
            $mode,
            $zenTargetDelayMs,
        );
        $targetScheduleSamples = 0;
        $targetAtMinimumSamples = 0;
        $targetCadenceFractions = [];
        $targetCellsByDimension = [2 => [], 4 => []];
        $lateTargetSchedules = 0;
        $finalMissAt = null;
        $finalMissHandledAt = null;
        $survivalMs = null;

        foreach ($proof->events as $eventIndex => $event) {
            $type = $event[0];
            $logicalAt = $event[1];
            $this->assertTimestamp($logicalAt, $eventIndex);
            if ($mode === 'zen' && $type !== RunProof::EVENT_FINISH && $logicalAt > self::ZEN_DURATION_MS) {
                $this->invalid('Zen gameplay continues beyond its deadline.', $eventIndex);
            }
            if ($mode === 'normal' && $logicalAt > self::MAX_NORMAL_DURATION_MS) {
                $this->invalid('Arcade run duration is outside the supported range.', $eventIndex);
            }
            if (
                in_array($type, [
                    RunProof::EVENT_TARGET,
                    RunProof::EVENT_DECOY_ACTIVATE,
                    RunProof::EVENT_DECOY_EXPIRE,
                    RunProof::EVENT_DECOY_TICK,
                ], true)
                && $logicalAt < $lastHandledAt
            ) {
                $this->invalid('A scheduled transition predates the previous input handler.', $eventIndex);
            }
            if ($finished || ($gameOver && $type !== RunProof::EVENT_FINISH)) {
                $this->invalid('Run proof continues after game over.', $eventIndex);
            }
            if (
                !$gameOver
                && $state === 'waiting'
                && !in_array($type, [RunProof::EVENT_TARGET, RunProof::EVENT_MISS], true)
                && $logicalAt > $targetSchedule['maximum'] + self::MAX_TRANSITION_LAG_MS
            ) {
                $this->invalid('The next target transition is missing from the proof.', $eventIndex);
            }
            if (
                $mode !== 'zen'
                &&
                $state === 'active'
                && $targetAt !== null
                && $targetDifficulty !== null
                && !in_array($type, [RunProof::EVENT_HIT, RunProof::EVENT_MISS, RunProof::EVENT_FINISH], true)
                && $logicalAt > $targetAt + $targetDifficulty['responseWindowMs'] + self::MAX_TRANSITION_LAG_MS
            ) {
                $this->invalid('The active target deadline transition is missing from the proof.', $eventIndex);
            }

            $processedAt = in_array($type, [
                RunProof::EVENT_HIT,
                RunProof::EVENT_MISS,
                RunProof::EVENT_FINISH,
            ], true) ? $event[2] : $logicalAt;
            if (
                !$gameOver
                && !in_array($type, [RunProof::EVENT_DECOY_ACTIVATE, RunProof::EVENT_DECOY_TICK], true)
                && $processedAt > $decoySchedule['maximum'] + self::MAX_TRANSITION_LAG_MS
            ) {
                // A browser can delay an independent timer while its main thread
                // is blocked. Preserve the run for review, but prevent omitted
                // decoy opportunities from producing ranked points or coins.
                $missingDecoyTransitions++;
                $difficulty = $state === 'active' && $targetDifficulty !== null
                    ? $targetDifficulty
                    : $this->difficulty($hits, $processedAt, $challengeStartHits);
                $decoySchedule = $this->decoyScheduleAfterOpportunity($processedAt, $difficulty);
            }

            if ($type === RunProof::EVENT_TARGET) {
                [, $at, $cell] = $event;
                if ($state !== 'waiting') {
                    $this->invalid('A target was activated while another target was active.', $eventIndex);
                }
                $this->assertNoExpiredDecoys($activeDecoys, $at, $eventIndex);
                if ($at + self::TIMESTAMP_QUANTIZATION_TOLERANCE_MS < $targetSchedule['minimum']) {
                    $this->invalid('A target appeared before its minimum quiet interval.', $eventIndex);
                }
                if ($at > $targetSchedule['maximum'] + self::MAX_TRANSITION_LAG_MS) {
                    $this->invalid('A target appeared outside its scheduling window.', $eventIndex);
                }
                $targetScheduleSamples++;
                $targetRange = $targetSchedule['maximum'] - $targetSchedule['minimum'];
                if ($mode !== 'zen' && $targetRange > 0) {
                    $targetCadenceFractions[] = max(
                        0,
                        ($at - $targetSchedule['minimum']) / $targetRange,
                    );
                }
                if (
                    $mode !== 'zen'
                    && abs($at - $targetSchedule['minimum']) <= self::TIMESTAMP_QUANTIZATION_TOLERANCE_MS
                ) {
                    $targetAtMinimumSamples++;
                }
                if ($at > $targetSchedule['maximum'] + self::SCHEDULER_LAG_RISK_MS) {
                    $lateTargetSchedules++;
                }

                if ($at >= self::FOUR_BY_FOUR_CHALLENGE_STARTS_AT_MS && $challengeStartHits === null) {
                    $challengeStartHits = $hits;
                }
                $difficulty = $this->difficulty($hits, $at, $challengeStartHits);
                $this->assertCell($cell, $difficulty['gridDimension'], $eventIndex);
                if (isset($targetCellsByDimension[$difficulty['gridDimension']])) {
                    $targetCellsByDimension[$difficulty['gridDimension']][] = $cell;
                }
                if (isset($activeDecoys[$cell])) {
                    $this->invalid('A target reused a reserved decoy cell.', $eventIndex);
                }
                if (isset($recentlyExpiredCells[$cell])) {
                    $hasUnreservedCell = false;
                    for ($candidate = 0; $candidate < $difficulty['gridDimension'] ** 2; $candidate++) {
                        if (!isset($activeDecoys[$candidate]) && !isset($recentlyExpiredCells[$candidate])) {
                            $hasUnreservedCell = true;
                            break;
                        }
                    }
                    if ($hasUnreservedCell) {
                        $this->invalid('A target reused a reserved decoy cell.', $eventIndex);
                    }
                }

                $state = 'active';
                $targetAt = $at;
                $targetCell = $cell;
                $targetDifficulty = $difficulty;
                $recentlyExpiredCells = [];
            } elseif ($type === RunProof::EVENT_HIT) {
                [, $inputAt, $handledAt, $cell] = $event;
                $this->assertHandledAt($inputAt, $handledAt, $lastHandledAt, $eventIndex);
                $handlerLags[] = $handledAt - $inputAt;
                if ($state !== 'active' || $targetAt === null || $targetDifficulty === null) {
                    $this->invalid('A correct tap has no active target.', $eventIndex);
                }
                $this->assertCell($cell, $targetDifficulty['gridDimension'], $eventIndex);
                $reactionMs = $inputAt - $targetAt;
                if (
                    $reactionMs < 0
                    || ($mode !== 'zen' && $reactionMs >= $targetDifficulty['responseWindowMs'])
                    || $cell !== $targetCell
                ) {
                    $this->invalid('A claimed correct tap does not match the active target.', $eventIndex);
                }

                $rating = $this->rating($reactionMs);
                $basePoints = $this->scoreReaction($reactionMs, $targetDifficulty['responseWindowMs']);
                $multiplierUsed = $multiplier;
                $points = $basePoints * $multiplierUsed;
                $score += $points;
                $reactionBasePoints += $basePoints;
                $multiplierBonusPoints += $points - $basePoints;
                $multiplierHits[$multiplierUsed]++;
                $multiplierBases[$multiplierUsed] += $basePoints;
                $maximumMultiplierUsed = max($maximumMultiplierUsed, $multiplierUsed);
                $hits++;
                $ratings[$rating]++;
                $reactions[] = $reactionMs;

                $steps = $rating === 'godlike' ? 2 : ($rating === 'perfect' ? 1 : 0);
                if ($steps > 0) {
                    [$multiplier, $streakProgress] = $this->advanceStreak(
                        $multiplier,
                        $streakProgress,
                        $steps,
                    );
                }

                if ($mode === 'zen') {
                    $zenTargetDelayMs += self::ZEN_CADENCE_ADAPTATION
                        * ($reactionMs - $zenTargetDelayMs);
                }
                $state = 'waiting';
                $targetAt = null;
                $targetCell = null;
                $targetDifficulty = null;
                $activeDecoys = [];
                $targetSchedule = $this->targetSchedule(
                    $handledAt,
                    0,
                    $hits,
                    $challengeStartHits,
                    $mode,
                    $zenTargetDelayMs,
                );
                $lastHandledAt = $handledAt;
            } elseif ($type === RunProof::EVENT_MISS) {
                [, $inputAt, $handledAt, $reason, $cell] = $event;
                $this->assertHandledAt($inputAt, $handledAt, $lastHandledAt, $eventIndex);
                $handlerLags[] = $handledAt - $inputAt;
                if (!in_array($reason, [RunProof::MISS_EMPTY, RunProof::MISS_WRONG, RunProof::MISS_LATE], true)) {
                    $this->invalid('Miss reason is invalid.', $eventIndex);
                }

                if ($state === 'waiting') {
                    if ($reason !== RunProof::MISS_EMPTY) {
                        $this->invalid('A waiting-board mistake must be an empty-cell tap.', $eventIndex);
                    }
                    if ($inputAt > $targetSchedule['maximum'] + self::MAX_TRANSITION_LAG_MS) {
                        $this->invalid('An empty-board tap occurs after a target should have appeared.', $eventIndex);
                    }
                    $difficulty = $this->difficulty($hits, $inputAt, $challengeStartHits);
                    $this->assertCell($cell, $difficulty['gridDimension'], $eventIndex);
                } elseif ($state === 'active' && $targetAt !== null && $targetDifficulty !== null) {
                    $reactionMs = $inputAt - $targetAt;
                    if ($reason === RunProof::MISS_EMPTY || $reactionMs < 0) {
                        $this->invalid('Active-target miss reason is invalid.', $eventIndex);
                    }
                    if ($reason === RunProof::MISS_WRONG) {
                        $this->assertCell($cell, $targetDifficulty['gridDimension'], $eventIndex);
                        if (
                            ($mode !== 'zen' && $reactionMs >= $targetDifficulty['responseWindowMs'])
                            || $cell === $targetCell
                        ) {
                            $this->invalid('Wrong-color miss does not match the active target.', $eventIndex);
                        }
                    } elseif ($reason === RunProof::MISS_LATE) {
                        if ($mode === 'zen') {
                            $this->invalid('Zen targets do not expire.', $eventIndex);
                        }
                        if ($reactionMs < $targetDifficulty['responseWindowMs']) {
                            $this->invalid('Late miss occurred before the response deadline.', $eventIndex);
                        }
                        if ($reactionMs > $targetDifficulty['responseWindowMs'] + self::MAX_TRANSITION_LAG_MS) {
                            $this->invalid('Late miss occurs too far beyond the response deadline.', $eventIndex);
                        }
                        if ($cell !== -1) {
                            $this->assertCell($cell, $targetDifficulty['gridDimension'], $eventIndex);
                        }
                    }
                } else {
                    $this->invalid('Miss occurred in an invalid run state.', $eventIndex);
                }

                $misses++;
                $multiplier = 1;
                $streakProgress = 0;
                $retainZenTarget = $mode === 'zen' && $state === 'active';
                if (!$retainZenTarget) {
                    $state = 'waiting';
                    $targetAt = null;
                    $targetCell = null;
                    $targetDifficulty = null;
                }
                $activeDecoys = [];
                $lastHandledAt = $handledAt;

                if ($mode === 'normal') {
                    $lives--;
                    if ($lives < 0) {
                        $this->invalid('Arcade run lost more than three lives.', $eventIndex);
                    }
                    if ($lives === 0) {
                        $gameOver = true;
                        $finalMissAt = $inputAt;
                        $finalMissHandledAt = $handledAt;
                    } else {
                        $targetSchedule = $this->targetSchedule(
                            $handledAt,
                            self::LIFE_LOSS_RECOVERY_MS,
                            $hits,
                            $challengeStartHits,
                        );
                        $decoySchedule = $this->nextDecoySchedule(
                            $handledAt,
                            self::LIFE_LOSS_RECOVERY_MS,
                            $hits,
                            $challengeStartHits,
                        );
                    }
                } elseif (!$retainZenTarget && $state !== 'waiting') {
                    $this->invalid('Zen miss left the run in an invalid state.', $eventIndex);
                }
            } elseif ($type === RunProof::EVENT_DECOY_ACTIVATE) {
                [, $at, $id, $cell, $lifetime] = $event;
                if ($id !== $nextDecoyId || $lifetime < self::DECOY_LIFETIME_MIN_MS || $lifetime > self::DECOY_LIFETIME_MAX_MS) {
                    $this->invalid('Decoy identity or lifetime is invalid.', $eventIndex);
                }
                if ($at + self::TIMESTAMP_QUANTIZATION_TOLERANCE_MS < $decoySchedule['minimum']) {
                    $this->invalid('A decoy appeared before its minimum quiet interval.', $eventIndex);
                }
                if ($at > $decoySchedule['maximum'] + self::MAX_TRANSITION_LAG_MS) {
                    $this->invalid('A decoy appeared outside its scheduling window.', $eventIndex);
                }
                if ($at > $decoySchedule['maximum'] + self::SCHEDULER_LAG_RISK_MS) {
                    $lateDecoySchedules++;
                }
                $decoyRange = $decoySchedule['maximum'] - $decoySchedule['minimum'];
                if ($decoyRange > 0) {
                    $decoyScheduleSamples++;
                    $decoyCadenceFractions[] = max(
                        0,
                        ($at - $decoySchedule['minimum']) / $decoyRange,
                    );
                }
                $this->assertNoExpiredDecoys($activeDecoys, $at, $eventIndex);
                $difficulty = $state === 'active' && $targetDifficulty !== null
                    ? $targetDifficulty
                    : $this->difficulty($hits, $at, $challengeStartHits);
                $cellCount = $difficulty['gridDimension'] ** 2;
                $capacity = min($difficulty['maximumActiveDecoys'], max(0, $cellCount - 1));
                if ($difficulty['decoyDelayRangeMs'] === null || $capacity === 0 || count($activeDecoys) >= $capacity) {
                    $this->invalid('Decoy is not allowed at this difficulty.', $eventIndex);
                }
                $this->assertCell($cell, $difficulty['gridDimension'], $eventIndex);
                if ($cell === $targetCell || isset($activeDecoys[$cell])) {
                    $this->invalid('Decoy overlaps an occupied cell.', $eventIndex);
                }

                $activeDecoys[$cell] = ['id' => $id, 'cell' => $cell, 'expiresAt' => $at + $lifetime];
                $nextDecoyId++;
                $decoyLifetimes[] = $lifetime;
                $decoySchedule = $this->decoyScheduleAfterOpportunity($at, $difficulty);
            } elseif ($type === RunProof::EVENT_DECOY_TICK) {
                [, $at] = $event;
                if ($at + self::TIMESTAMP_QUANTIZATION_TOLERANCE_MS < $decoySchedule['minimum']) {
                    $this->invalid('A decoy opportunity occurred before its minimum quiet interval.', $eventIndex);
                }
                if ($at > $decoySchedule['maximum'] + self::MAX_TRANSITION_LAG_MS) {
                    $this->invalid('A decoy opportunity occurred outside its scheduling window.', $eventIndex);
                }
                if ($at > $decoySchedule['maximum'] + self::SCHEDULER_LAG_RISK_MS) {
                    $lateDecoySchedules++;
                }
                $decoyRange = $decoySchedule['maximum'] - $decoySchedule['minimum'];
                if ($decoyRange > 0) {
                    $decoyScheduleSamples++;
                    $decoyCadenceFractions[] = max(
                        0,
                        ($at - $decoySchedule['minimum']) / $decoyRange,
                    );
                }
                $this->assertNoExpiredDecoys($activeDecoys, $at, $eventIndex);
                $difficulty = $state === 'active' && $targetDifficulty !== null
                    ? $targetDifficulty
                    : $this->difficulty($hits, $at, $challengeStartHits);
                $cellCount = $difficulty['gridDimension'] ** 2;
                $capacity = min($difficulty['maximumActiveDecoys'], max(0, $cellCount - 1));
                $canActivate = $difficulty['decoyDelayRangeMs'] !== null
                    && $capacity > 0
                    && count($activeDecoys) < $capacity;
                if ($canActivate) {
                    $this->invalid('An ignored decoy opportunity could have produced a visible decoy.', $eventIndex);
                }
                $decoySchedule = $this->decoyScheduleAfterOpportunity($at, $difficulty);
            } elseif ($type === RunProof::EVENT_DECOY_EXPIRE) {
                $at = $event[1];
                $ids = array_slice($event, 2);
                $expiredById = [];
                foreach ($activeDecoys as $decoy) {
                    if ($decoy['expiresAt'] <= $at) {
                        $expiredById[$decoy['id']] = $decoy;
                    }
                }
                sort($ids, SORT_NUMERIC);
                $expectedIds = array_keys($expiredById);
                sort($expectedIds, SORT_NUMERIC);
                if ($ids !== $expectedIds || $ids === []) {
                    $this->invalid('Decoy expiry does not match visible expired decoys.', $eventIndex);
                }
                foreach ($expiredById as $decoy) {
                    unset($activeDecoys[$decoy['cell']]);
                    $recentlyExpiredCells[$decoy['cell']] = true;
                    $dodges++;
                    if ($mode !== 'zen') {
                        $score += self::DODGE_POINTS;
                    }
                }
            } elseif ($type === RunProof::EVENT_FINISH) {
                [, $logicalFinishAt, $handledAt] = $event;
                $this->assertHandledAt($logicalFinishAt, $handledAt, $lastHandledAt, $eventIndex);
                if ($eventIndex !== $proof->eventCount() - 1) {
                    $this->invalid('Finish must be the final proof event.', $eventIndex);
                }
                if ($mode === 'normal') {
                    if (
                        !$gameOver
                        || $misses !== self::STARTING_LIVES
                        || $logicalFinishAt !== $finalMissAt
                        || $handledAt < $finalMissHandledAt
                    ) {
                        $this->invalid('Arcade run did not finish on its third life loss.', $eventIndex);
                    }
                } else {
                    if ($logicalFinishAt !== self::ZEN_DURATION_MS) {
                        $this->invalid('Zen run must finish at exactly three minutes.', $eventIndex);
                    }
                }
                $survivalMs = $logicalFinishAt;
                $finished = true;
                $lastHandledAt = $handledAt;
            } else {
                $this->invalid('Unknown proof event.', $eventIndex);
            }

            if (in_array($type, [
                RunProof::EVENT_TARGET,
                RunProof::EVENT_DECOY_ACTIVATE,
                RunProof::EVENT_DECOY_EXPIRE,
                RunProof::EVENT_DECOY_TICK,
            ], true)) {
                $lastHandledAt = $logicalAt;
            }
        }

        if (!$finished || $survivalMs === null) {
            throw new ApiException(400, 'Run proof has no valid finish event.');
        }
        if ($mode === 'normal' && $misses !== self::STARTING_LIVES) {
            throw new ApiException(400, 'Arcade run must end after exactly three mistakes.');
        }

        $fastest = $reactions === [] ? null : min($reactions);
        $average = $reactions === [] ? null : (int) round(array_sum($reactions) / count($reactions));
        [$riskScore, $riskLevel, $riskFlags] = $this->assessRisk(
            $reactions,
            $handlerLags,
            $decoyLifetimes,
            $decoyCadenceFractions,
            $targetCadenceFractions,
            $targetCellsByDimension,
            $targetScheduleSamples,
            $targetAtMinimumSamples,
            $lateTargetSchedules,
            $decoyScheduleSamples,
            $missingDecoyTransitions,
            $lateDecoySchedules,
            $survivalMs,
            $hits,
        );

        return new ScoreSubmission(
            runId: $proof->runId,
            mode: $mode,
            score: $score,
            reactionBasePoints: $reactionBasePoints,
            multiplierBonusPoints: $multiplierBonusPoints,
            maxMultiplier: $maximumMultiplierUsed,
            multiplierOneHits: $multiplierHits[1],
            multiplierTwoHits: $multiplierHits[2],
            multiplierThreeHits: $multiplierHits[3],
            multiplierFourHits: $multiplierHits[4],
            multiplierFiveHits: $multiplierHits[5],
            multiplierOneBasePoints: $multiplierBases[1],
            multiplierTwoBasePoints: $multiplierBases[2],
            multiplierThreeBasePoints: $multiplierBases[3],
            multiplierFourBasePoints: $multiplierBases[4],
            multiplierFiveBasePoints: $multiplierBases[5],
            hits: $hits,
            misses: $misses,
            dodges: $dodges,
            survivalMs: $survivalMs,
            fastestReactionMs: $fastest,
            averageReactionMs: $average,
            godlikeCount: $ratings['godlike'],
            perfectCount: $ratings['perfect'],
            greatCount: $ratings['great'],
            goodCount: $ratings['good'],
            proofHash: $proof->proofHash(),
            riskScore: $riskScore,
            riskLevel: $riskLevel,
            riskFlags: $riskFlags,
        );
    }

    private function difficulty(int $hits, int $elapsedMs, ?int $challengeStartHits): array
    {
        $gridDimension = $elapsedMs >= self::FOUR_BY_FOUR_STARTS_AT_MS
            ? 4
            : ($hits >= self::TWO_BY_TWO_STARTS_AT_HITS ? 2 : 1);
        $responseWindowMs = 1_000;
        $spawnRange = [550, 1_100];
        $decoyRange = null;
        $maximumActiveDecoys = 0;

        if ($elapsedMs >= self::COLOR_PATIENCE_STARTS_AT_MS) {
            $spawnRange = [550, 1_000];
            $decoyRange = [2_200, 3_600];
            $maximumActiveDecoys = 1;
        }
        if ($elapsedMs >= self::GENTLE_RAMP_STARTS_AT_MS) {
            $progress = min(1, max(0, ($elapsedMs - self::GENTLE_RAMP_STARTS_AT_MS) / 10_000));
            $responseWindowMs = (int) round(1_000 - 250 * $progress);
            $spawnRange = [500, 950];
            $decoyRange = [2_000, 3_200];
            $maximumActiveDecoys = 1;
        }
        if ($elapsedMs >= self::RARE_DECOYS_STARTS_AT_MS) {
            $responseWindowMs = 750;
            $spawnRange = [475, 900];
            $decoyRange = [600, 3_400];
            $maximumActiveDecoys = 2;
        }
        if ($elapsedMs >= self::FOUR_BY_FOUR_STARTS_AT_MS) {
            $responseWindowMs = 1_000;
            $spawnRange = [525, 950];
            $decoyRange = [2_200, 3_400];
            $maximumActiveDecoys = 1;
        }
        if ($elapsedMs >= self::FOUR_BY_FOUR_CHALLENGE_STARTS_AT_MS) {
            $challengeHits = $challengeStartHits === null ? 0 : max(0, $hits - $challengeStartHits);
            $tier = intdiv($challengeHits, 10);
            $responseWindowMs = max(200, 1_000 - $challengeHits * 10);
            $spawnRange = [
                max(250, 425 - $tier * 15),
                max(500, 825 - $tier * 25),
            ];
            $decoyRange = [600, max(1_100, 2_000 - $tier * 170)];
            $maximumActiveDecoys = min(6, 2 + $tier);
        }

        return [
            'gridDimension' => $gridDimension,
            'responseWindowMs' => $responseWindowMs,
            'spawnDelayRangeMs' => $spawnRange,
            'decoyDelayRangeMs' => $decoyRange,
            'maximumActiveDecoys' => $maximumActiveDecoys,
        ];
    }

    private function targetSchedule(
        int $baseAt,
        int $recoveryMs,
        int $hits,
        ?int $challengeStartHits,
        string $mode = 'normal',
        float $zenTargetDelayMs = self::ZEN_INITIAL_TARGET_DELAY_MS,
    ): array {
        $readyAt = $baseAt + $recoveryMs;
        if ($mode === 'zen') {
            $targetAt = $readyAt + $zenTargetDelayMs;
            return ['minimum' => $targetAt, 'maximum' => $targetAt];
        }
        $range = $this->difficulty($hits, $readyAt, $challengeStartHits)['spawnDelayRangeMs'];
        return ['minimum' => $readyAt + $range[0], 'maximum' => $readyAt + $range[1]];
    }

    private function nextDecoySchedule(
        int $baseAt,
        int $recoveryMs,
        int $hits,
        ?int $challengeStartHits,
    ): array {
        $readyAt = $baseAt + $recoveryMs;
        if ($readyAt < self::COLOR_PATIENCE_STARTS_AT_MS) {
            return [
                'minimum' => self::COLOR_PATIENCE_STARTS_AT_MS,
                'maximum' => self::COLOR_PATIENCE_STARTS_AT_MS,
            ];
        }
        $difficulty = $this->difficulty($hits, $readyAt, $challengeStartHits);
        if ($difficulty['gridDimension'] < 2 || $difficulty['decoyDelayRangeMs'] === null) {
            return [
                'minimum' => $readyAt + self::DECOY_RETRY_MS,
                'maximum' => $readyAt + self::DECOY_RETRY_MS,
            ];
        }
        return [
            'minimum' => $readyAt + $difficulty['decoyDelayRangeMs'][0],
            'maximum' => $readyAt + $difficulty['decoyDelayRangeMs'][1],
        ];
    }

    private function decoyScheduleAfterOpportunity(int $at, array $difficulty): array
    {
        if ($difficulty['gridDimension'] < 2 || $difficulty['decoyDelayRangeMs'] === null) {
            return [
                'minimum' => $at + self::DECOY_RETRY_MS,
                'maximum' => $at + self::DECOY_RETRY_MS,
            ];
        }
        return [
            'minimum' => $at + $difficulty['decoyDelayRangeMs'][0],
            'maximum' => $at + $difficulty['decoyDelayRangeMs'][1],
        ];
    }

    private function scoreReaction(int $reactionMs, int $responseWindowMs): int
    {
        $remaining = min(1, max(0, 1 - $reactionMs / $responseWindowMs));
        return (int) round(self::SCORE_FLOOR + (self::SCORE_CEILING - self::SCORE_FLOOR) * ($remaining ** 2));
    }

    private function rating(int $reactionMs): string
    {
        if ($reactionMs < 250) return 'godlike';
        if ($reactionMs < 350) return 'perfect';
        if ($reactionMs < 450) return 'great';
        return 'good';
    }

    private function advanceStreak(int $multiplier, int $progress, int $steps): array
    {
        if ($multiplier >= self::MAX_MULTIPLIER) {
            return [self::MAX_MULTIPLIER, self::STREAK_TARGET];
        }
        $progress += $steps;
        while ($progress >= self::STREAK_TARGET && $multiplier < self::MAX_MULTIPLIER) {
            $progress -= self::STREAK_TARGET;
            $multiplier++;
        }
        if ($multiplier >= self::MAX_MULTIPLIER) {
            $progress = self::STREAK_TARGET;
        }
        return [$multiplier, $progress];
    }

    private function assessRisk(
        array $reactions,
        array $handlerLags,
        array $decoyLifetimes,
        array $decoyCadenceFractions,
        array $targetCadenceFractions,
        array $targetCellsByDimension,
        int $targetSamples,
        int $targetAtMinimumSamples,
        int $lateTargetSchedules,
        int $decoyScheduleSamples,
        int $missingDecoyTransitions,
        int $lateDecoySchedules,
        int $survivalMs,
        int $hits,
    ): array {
        $score = 0;
        $flags = [];
        $count = count($reactions);

        if ($count >= 12) {
            $underEighty = count(array_filter($reactions, static fn (int $value): bool => $value < 80));
            if ($underEighty / $count >= 0.8) {
                $score += 100;
                $flags[] = 'implausibly_fast_reactions';
            } elseif (min($reactions) < 35) {
                $score += 25;
                $flags[] = 'very_low_reaction_sample';
            }
        }

        if ($count >= 20) {
            $mean = array_sum($reactions) / $count;
            $variance = array_sum(array_map(
                static fn (int $value): float => ($value - $mean) ** 2,
                $reactions,
            )) / $count;
            if (sqrt($variance) <= 2.0 && max($reactions) - min($reactions) <= 5) {
                $score += 100;
                $flags[] = 'near_identical_reactions';
            }
        }
        if ($count >= 50) {
            $godlike = count(array_filter(
                $reactions,
                static fn (int $value): bool => $value < 250,
            ));
            if ($godlike / $count >= 0.98) {
                $score += 100;
                $flags[] = 'near_uniform_godlike_reactions';
            }
        }
        if ($count >= 80) {
            $meanReaction = array_sum($reactions) / $count;
            $godlike = count(array_filter(
                $reactions,
                static fn (int $value): bool => $value < 250,
            ));
            if ($meanReaction < 220 && $godlike / $count >= 0.85) {
                $score += 100;
                $flags[] = 'sustained_elite_reactions';
            }
        }

        if ($targetSamples >= 12 && $targetAtMinimumSamples / $targetSamples >= 0.9) {
            $score += 100;
            $flags[] = 'non_random_target_cadence';
        }
        if (
            count($targetCadenceFractions) >= 20
            && array_sum(array_map(
                static fn (float $value): float => min(1, $value),
                $targetCadenceFractions,
            )) / count($targetCadenceFractions) < 0.18
        ) {
            $score += 100;
            $flags[] = 'accelerated_target_cadence';
        }
        foreach ($targetCellsByDimension as $dimension => $cells) {
            if (count($cells) < 16) continue;
            $frequencies = array_count_values($cells);
            $dominantShare = max($frequencies) / count($cells);
            $distinctShare = count($frequencies) / ($dimension ** 2);
            if ($dominantShare >= 0.8 || ($dimension === 4 && $distinctShare <= 0.25)) {
                $score += 100;
                $flags[] = 'non_random_target_positions';
                break;
            }
        }
        if (count($handlerLags) >= 20 && max($handlerLags) === 0) {
            $score += 20;
            $flags[] = 'zero_dispatch_latency';
        }
        if (
            count($decoyLifetimes) >= 10
            && (
                max($decoyLifetimes) - min($decoyLifetimes) < 20
                || array_sum($decoyLifetimes) / count($decoyLifetimes) < 500
            )
        ) {
            $score += 100;
            $flags[] = 'non_random_decoy_lifetimes';
        }
        if (
            count($decoyCadenceFractions) >= 10
            && array_sum(array_map(
                static fn (float $value): float => min(1, $value),
                $decoyCadenceFractions,
            )) / count($decoyCadenceFractions) < 0.15
        ) {
            $score += 100;
            $flags[] = 'accelerated_decoy_cadence';
        }
        $minimumExpectedDecoys = intdiv(max(0, $survivalMs - self::COLOR_PATIENCE_STARTS_AT_MS), 30_000);
        if ($hits >= self::TWO_BY_TWO_STARTS_AT_HITS && count($decoyLifetimes) < $minimumExpectedDecoys) {
            $score += 100;
            $flags[] = 'missing_decoy_cadence';
        }
        if ($missingDecoyTransitions > 0) {
            $score += 100;
            $flags[] = 'missing_decoy_transitions';
        }
        if (
            $lateDecoySchedules >= 3
            && $decoyScheduleSamples > 0
            && $lateDecoySchedules / $decoyScheduleSamples >= 0.2
        ) {
            $score += 100;
            $flags[] = 'sustained_decoy_scheduler_lag';
        } elseif ($lateDecoySchedules > 0) {
            $flags[] = 'occasional_decoy_scheduler_lag';
        }
        if ($targetSamples >= 10 && $lateTargetSchedules / $targetSamples >= 0.5) {
            $score += 100;
            $flags[] = 'sustained_target_scheduler_lag';
        }

        $score = min(200, $score);
        $level = $score >= 100 ? 'high' : ($score >= 40 ? 'elevated' : 'low');
        return [$score, $level, $flags];
    }

    private function assertTimestamp(int $value, int $eventIndex): void
    {
        if ($value < 0) {
            $this->invalid('Run proof timestamp is invalid.', $eventIndex);
        }
    }

    private function assertHandledAt(int $logicalAt, int $handledAt, int $lastHandledAt, int $eventIndex): void
    {
        if (
            $logicalAt < 0
            || $handledAt < $logicalAt
            || $handledAt < $lastHandledAt
            || $handledAt - $logicalAt > self::MAX_HANDLER_LAG_MS
        ) {
            $this->invalid('Input handling timestamps are invalid.', $eventIndex);
        }
    }

    private function assertCell(int $cell, int $dimension, int $eventIndex): void
    {
        if ($cell < 0 || $cell >= $dimension ** 2) {
            $this->invalid('Cell index is invalid for the active grid.', $eventIndex);
        }
    }

    private function assertNoExpiredDecoys(array $activeDecoys, int $at, int $eventIndex): void
    {
        foreach ($activeDecoys as $decoy) {
            // Proof timestamps are integer milliseconds while the browser keeps
            // sub-millisecond rAF times. Equality can therefore mean that the
            // transition really occurred just before expiry.
            if ($decoy['expiresAt'] < $at) {
                $this->invalid('Expired decoys must be settled before the next transition.', $eventIndex);
            }
        }
    }

    private function invalid(string $message, int $eventIndex): never
    {
        throw new ApiException(400, $message . ' (event ' . $eventIndex . ')');
    }
}
