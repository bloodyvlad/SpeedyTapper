<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

final class RunSubmissionService
{
    private const SERVER_CLOCK_TOLERANCE_MS = 500;
    private const MAX_UNACCOUNTED_SERVER_MS = 30_000;
    private const SUBMISSION_LIMIT_PER_MINUTE = 10;
    private const SUBMISSION_LIMIT_PER_DAY = 500;

    public function __construct(
        private readonly PDO $database,
        private readonly LeaderboardRepository $leaderboard,
        private readonly RunProofValidator $validator,
        private readonly AchievementService $achievements,
    ) {
    }

    public function submit(string $playerId, string $sessionBindingHash, RunProof $proof): array
    {
        $record = $this->record($playerId, $sessionBindingHash, $proof, true);
        $payload = $this->leaderboard->payload($proof->mode, $playerId, $proof->runId);
        $hasExactSubmittedContext = is_string($payload['contextEntryId'])
            && hash_equals($proof->runId, $payload['contextEntryId']);
        $payload['rank'] = $hasExactSubmittedContext ? $payload['contextRank'] : null;
        $payload['submittedRank'] = $hasExactSubmittedContext ? $payload['contextRank'] : null;
        $payload['submittedEntryId'] = $hasExactSubmittedContext ? $payload['contextEntryId'] : null;
        $payload['improved'] = $record['improved'];
        $payload['duplicate'] = $record['duplicate'];
        $payload['verificationStatus'] = $record['verificationStatus'];
        $payload['coinsEarned'] = $record['coinsEarned'];
        $payload['coinBalance'] = $record['coinBalance'];
        $payload['totalPlayMs'] = $record['totalPlayMs'];
        $payload['verifiedResult'] = $record['verifiedResult'];
        return $payload;
    }

    private function record(
        string $playerId,
        string $sessionBindingHash,
        RunProof $proof,
        bool $mayRetry,
    ): array {
        if (strlen($sessionBindingHash) !== 32) {
            throw new ApiException(403, 'Run session is invalid. Refresh and try again.');
        }

        $this->database->beginTransaction();
        try {
            $player = $this->lockPlayer($playerId);
            $attempt = $this->lockAttempt($proof->runId);
            $this->assertAttemptOwnership($attempt, $playerId, $sessionBindingHash, $proof);

            if ((string) $attempt['status'] === 'completed') {
                $existing = $this->findCompletedRun($proof->runId);
                if (!is_array($existing)) {
                    throw new ApiException(409, 'Run completion is inconsistent. Contact support.');
                }
                $this->assertMatchingRun($existing, $playerId, $proof);
                $this->database->commit();
                return $this->existingRecord($existing, $player);
            }

            if ((string) $attempt['status'] !== 'issued') {
                throw new ApiException(409, 'This run is no longer eligible for submission.');
            }
            if ((int) $attempt['is_expired'] === 1) {
                $this->updateAttemptStatus($proof->runId, 'expired', 'lease-expired');
                $this->database->commit();
                throw new ApiException(409, 'This ranked run expired before it was submitted.');
            }

            // Count every prior completion attempt, including rejected proofs,
            // before doing CPU-heavy replay or writing any proof metadata.
            $this->enforcePlayerRateLimit($playerId);

            try {
                $score = $this->validator->validate($proof);
            } catch (ApiException $error) {
                $this->rejectAttempt($proof, 'invalid-proof', $error->getMessage());
                $this->database->commit();
                throw $error;
            }
            $serverElapsedMs = (int) $attempt['server_elapsed_ms'];
            $proofHandledMs = $this->proofHandledMs($proof);
            if (
                $serverElapsedMs + self::SERVER_CLOCK_TOLERANCE_MS < $score->survivalMs
                || $serverElapsedMs + self::SERVER_CLOCK_TOLERANCE_MS < $proofHandledMs
            ) {
                $this->rejectAttempt(
                    $proof,
                    'clock-compression',
                    'The submitted run advanced faster than the server clock.',
                );
                $this->database->commit();
                throw new ApiException(400, 'Run timing could not be verified.');
            }
            $duplicateTrace = $this->claimTrace($proof);
            if ($duplicateTrace) {
                $score = $score->withRiskFlag('duplicate_event_trace');
            }
            if ($serverElapsedMs - $proofHandledMs > self::MAX_UNACCOUNTED_SERVER_MS) {
                $score = $score->withRiskFlag('submission_clock_gap');
            }

            $verificationStatus = $duplicateTrace
                ? 'quarantined'
                : ($score->riskLevel === 'high' ? 'review' : 'verified');
            $coinStatus = match ($verificationStatus) {
                'verified' => 'eligible',
                'review' => 'withheld',
                default => 'revoked',
            };
            $creditedPlayMs = min($score->survivalMs, $serverElapsedMs);
            $progression = $verificationStatus === 'verified'
                ? CoinProgression::accrue((int) $player['coin_time_remainder_ms'], $creditedPlayMs)
                : CoinProgression::accrue((int) $player['coin_time_remainder_ms'], 0);
            $wallet = CoinEconomy::applyCredit(
                (int) $player['coins'],
                (int) $player['coin_debt'],
                $progression->coinsEarned,
            );
            $coinDebt = $wallet['debt'];
            $coinBalance = $wallet['coins'];
            $totalCoinsCollected = (int) $player['total_coins_collected']
                + ($verificationStatus === 'verified' ? $progression->coinsEarned : 0);
            $totalPlayMs = (int) $player['total_play_ms']
                + ($verificationStatus === 'verified' ? $creditedPlayMs : 0);
            $improved = $this->leaderboard->insertResultInTransaction(
                $playerId,
                $score,
                $verificationStatus,
            );

            if ($verificationStatus === 'verified') {
                $updatePlayer = $this->database->prepare(
                    'UPDATE players SET coins = :coins, coin_debt = :coin_debt, '
                    . 'total_coins_collected = :total_coins_collected, '
                    . 'coin_time_remainder_ms = :coin_time_remainder_ms, total_play_ms = :total_play_ms, '
                    . 'updated_at = UTC_TIMESTAMP(3) WHERE id = :player_id'
                );
                $updatePlayer->execute([
                    'coins' => $coinBalance,
                    'coin_debt' => $coinDebt,
                    'total_coins_collected' => $totalCoinsCollected,
                    'coin_time_remainder_ms' => $progression->remainderMs,
                    'total_play_ms' => $totalPlayMs,
                    'player_id' => $playerId,
                ]);
            }

            $this->insertCompletedRun(
                $playerId,
                $score,
                $serverElapsedMs,
                $improved,
                $verificationStatus,
                $coinStatus,
                $progression->coinsEarned,
                $creditedPlayMs,
            );
            if ($verificationStatus === 'verified') {
                $this->achievements->unlockForRunInTransaction(
                    $playerId,
                    $score,
                    $totalCoinsCollected,
                );
            }
            $this->insertProof($proof, 'verified', null);
            $this->insertCoinLedger(
                $playerId,
                $score,
                $verificationStatus === 'verified' ? $creditedPlayMs : 0,
                $progression->coinsEarned,
                (int) $player['coin_time_remainder_ms'],
                $progression->remainderMs,
                $coinBalance,
                $coinDebt,
                $totalPlayMs,
                $coinStatus,
            );
            $this->completeAttempt(
                $proof,
                $playerId,
                $serverElapsedMs,
                $score,
            );

            $this->database->commit();
            return [
                'improved' => $improved,
                'duplicate' => false,
                'verificationStatus' => $verificationStatus,
                'coinsEarned' => $progression->coinsEarned,
                'coinBalance' => $coinBalance,
                'totalPlayMs' => $totalPlayMs,
                'verifiedResult' => $this->publicScore($score),
            ];
        } catch (PDOException $error) {
            $this->rollBack();
            if ($mayRetry && ($error->getCode() === '23000' || $error->getCode() === '40001')) {
                return $this->record($playerId, $sessionBindingHash, $proof, false);
            }
            throw $error;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    private function lockPlayer(string $playerId): array
    {
        $statement = $this->database->prepare(
            'SELECT coins, coin_debt, total_coins_collected, coin_time_remainder_ms, total_play_ms '
            . 'FROM players WHERE id = :player_id FOR UPDATE'
        );
        $statement->execute(['player_id' => $playerId]);
        $player = $statement->fetch();
        if (!is_array($player)) {
            throw new ApiException(401, 'Sign in with Google to continue.');
        }
        return $player;
    }

    private function lockAttempt(string $runId): array
    {
        $statement = $this->database->prepare(
            'SELECT run_id, session_binding_hash, player_id, mode, build_id, ruleset_id, proof_version, '
            . 'status, (expires_at < UTC_TIMESTAMP(3)) AS is_expired, '
            . 'GREATEST(0, FLOOR(TIMESTAMPDIFF(MICROSECOND, started_at, UTC_TIMESTAMP(3)) / 1000)) '
            . 'AS server_elapsed_ms FROM run_attempts WHERE run_id = :run_id FOR UPDATE'
        );
        $statement->execute(['run_id' => $runId]);
        $attempt = $statement->fetch();
        if (!is_array($attempt)) {
            throw new ApiException(409, 'This result was not started by the verification server.');
        }
        return $attempt;
    }

    private function assertAttemptOwnership(
        array $attempt,
        string $playerId,
        string $bindingHash,
        RunProof $proof,
    ): void
    {
        $storedBinding = $attempt['session_binding_hash'] ?? null;
        if (!is_string($storedBinding) || !hash_equals($storedBinding, $bindingHash)) {
            throw new ApiException(403, 'This run belongs to a different browser session.');
        }
        $attemptPlayerId = $attempt['player_id'] ?? null;
        if (!is_string($attemptPlayerId) || !hash_equals($attemptPlayerId, $playerId)) {
            throw new ApiException(403, 'This ranked run belongs to a different player.');
        }
        if (
            !hash_equals((string) $attempt['mode'], $proof->mode)
            || !hash_equals((string) $attempt['build_id'], $proof->buildId)
            || !hash_equals((string) $attempt['ruleset_id'], $proof->ruleset)
            || (int) $attempt['proof_version'] !== $proof->proofVersion
        ) {
            throw new ApiException(409, 'Run ticket and proof do not match.');
        }
    }

    private function enforcePlayerRateLimit(string $playerId): void
    {
        $statement = $this->database->prepare(
            'SELECT run_id FROM run_attempts WHERE player_id = :player_id '
            . 'AND submitted_at > UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE '
            . 'ORDER BY submitted_at ASC LIMIT ' . (self::SUBMISSION_LIMIT_PER_MINUTE + 1) . ' FOR UPDATE'
        );
        $statement->execute(['player_id' => $playerId]);
        if (count($statement->fetchAll()) >= self::SUBMISSION_LIMIT_PER_MINUTE) {
            throw new ApiException(429, 'Too many score submissions. Try again shortly.', [
                'Retry-After' => '60',
            ]);
        }
        $daily = $this->database->prepare(
            'SELECT COUNT(*) FROM run_attempts WHERE player_id = :player_id '
            . 'AND submitted_at > UTC_TIMESTAMP(3) - INTERVAL 1 DAY'
        );
        $daily->execute(['player_id' => $playerId]);
        if ((int) $daily->fetchColumn() >= self::SUBMISSION_LIMIT_PER_DAY) {
            throw new ApiException(429, 'The daily score-submission limit has been reached. Try again tomorrow.', [
                'Retry-After' => '3600',
            ]);
        }
    }

    private function insertCompletedRun(
        string $playerId,
        ScoreSubmission $score,
        int $serverElapsedMs,
        bool $improved,
        string $verificationStatus,
        string $coinStatus,
        int $coinsAwarded,
        int $creditedPlayMs,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO completed_runs '
            . '(run_id, leaderboard_entry_id, player_id, payload_hash, mode, score, duration_ms, '
            . 'reaction_base_points, multiplier_bonus_points, max_multiplier, multiplier_1_hits, '
            . 'multiplier_2_hits, multiplier_3_hits, multiplier_4_hits, multiplier_5_hits, '
            . 'multiplier_1_base_points, multiplier_2_base_points, multiplier_3_base_points, '
            . 'multiplier_4_base_points, multiplier_5_base_points, coins_awarded, leaderboard_improved, '
            . 'verification_status, coin_status, ruleset_id, proof_version, verified_at, server_elapsed_ms, '
            . 'credited_play_ms, miss_count, risk_score, risk_reasons) VALUES '
            . '(:run_id, :leaderboard_entry_id, :player_id, :payload_hash, :mode, :score, :duration_ms, '
            . ':reaction_base_points, :multiplier_bonus_points, :max_multiplier, :multiplier_1_hits, '
            . ':multiplier_2_hits, :multiplier_3_hits, :multiplier_4_hits, :multiplier_5_hits, '
            . ':multiplier_1_base_points, :multiplier_2_base_points, :multiplier_3_base_points, '
            . ':multiplier_4_base_points, :multiplier_5_base_points, :coins_awarded, :leaderboard_improved, '
            . ':verification_status, :coin_status, :ruleset_id, :proof_version, UTC_TIMESTAMP(3), '
            . ':server_elapsed_ms, :credited_play_ms, :miss_count, :risk_score, :risk_reasons)'
        );
        $values = [
            'run_id' => $score->runId,
            'leaderboard_entry_id' => $score->runId,
            'player_id' => $playerId,
            'mode' => $score->mode,
            'score' => $score->score,
            'duration_ms' => $score->survivalMs,
            'reaction_base_points' => $score->reactionBasePoints,
            'multiplier_bonus_points' => $score->multiplierBonusPoints,
            'max_multiplier' => $score->maxMultiplier,
            'multiplier_1_hits' => $score->multiplierOneHits,
            'multiplier_2_hits' => $score->multiplierTwoHits,
            'multiplier_3_hits' => $score->multiplierThreeHits,
            'multiplier_4_hits' => $score->multiplierFourHits,
            'multiplier_5_hits' => $score->multiplierFiveHits,
            'multiplier_1_base_points' => $score->multiplierOneBasePoints,
            'multiplier_2_base_points' => $score->multiplierTwoBasePoints,
            'multiplier_3_base_points' => $score->multiplierThreeBasePoints,
            'multiplier_4_base_points' => $score->multiplierFourBasePoints,
            'multiplier_5_base_points' => $score->multiplierFiveBasePoints,
            'coins_awarded' => $coinsAwarded,
            'leaderboard_improved' => $improved ? 1 : 0,
            'verification_status' => $verificationStatus,
            'coin_status' => $coinStatus,
            'ruleset_id' => RunProofValidator::RULESET_ID,
            'proof_version' => RunProofValidator::PROOF_VERSION,
            'server_elapsed_ms' => $serverElapsedMs,
            'credited_play_ms' => $creditedPlayMs,
            'miss_count' => $score->misses,
            'risk_score' => $score->riskScore,
            'risk_reasons' => $this->riskReasons($score),
        ];
        foreach ($values as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':payload_hash', $score->proofHash, PDO::PARAM_LOB);
        $statement->execute();
    }

    private function insertProof(RunProof $proof, string $status, ?string $reason): void
    {
        $proofJson = $status === 'verified'
            ? $proof->canonicalJson()
            : json_encode([
                'redacted' => true,
                'runId' => $proof->runId,
                'mode' => $proof->mode,
                'buildId' => $proof->buildId,
                'ruleset' => $proof->ruleset,
                'proofVersion' => $proof->proofVersion,
                'eventCount' => $proof->eventCount(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $statement = $this->database->prepare(
            'INSERT INTO run_proofs '
            . '(run_id, proof_version, event_count, payload_hash, trace_hash, proof_json, validation_status, '
            . 'validation_reason, validated_at) VALUES '
            . '(:run_id, :proof_version, :event_count, :payload_hash, :trace_hash, :proof_json, :validation_status, '
            . ':validation_reason, UTC_TIMESTAMP(3))'
        );
        $statement->bindValue(':run_id', $proof->runId);
        $statement->bindValue(':proof_version', $proof->proofVersion, PDO::PARAM_INT);
        $statement->bindValue(':event_count', $proof->eventCount(), PDO::PARAM_INT);
        $statement->bindValue(':payload_hash', $proof->proofHash(), PDO::PARAM_LOB);
        $statement->bindValue(':trace_hash', $proof->traceHash(), PDO::PARAM_LOB);
        $statement->bindValue(':proof_json', $proofJson);
        $statement->bindValue(':validation_status', $status);
        $statement->bindValue(':validation_reason', $reason);
        $statement->execute();
    }

    private function claimTrace(RunProof $proof): bool
    {
        try {
            $statement = $this->database->prepare(
                'INSERT INTO run_trace_claims (trace_hash, first_run_id, claimed_at) '
                . 'VALUES (:trace_hash, :run_id, UTC_TIMESTAMP(3))'
            );
            $statement->bindValue(':trace_hash', $proof->traceHash(), PDO::PARAM_LOB);
            $statement->bindValue(':run_id', $proof->runId);
            $statement->execute();
            return false;
        } catch (PDOException $error) {
            $driverCode = (int) ($error->errorInfo[1] ?? 0);
            if ($error->getCode() === '23000' && $driverCode === 1062) {
                return true;
            }
            throw $error;
        }
    }

    private function insertCoinLedger(
        string $playerId,
        ScoreSubmission $score,
        int $creditedPlayMs,
        int $coinsEarned,
        int $remainderBefore,
        int $remainderAfter,
        int $coinBalance,
        int $coinDebt,
        int $totalPlayMs,
        string $coinStatus,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO coin_ledger '
            . '(event_id, event_key, player_id, run_id, event_type, play_ms_delta, coin_delta, '
            . 'remainder_before_ms, remainder_after_ms, coin_balance_after, coin_debt_after, '
            . 'total_play_ms_after, '
            . 'coin_status, actor, reason) VALUES '
            . '(:event_id, :event_key, :player_id, :run_id, \'run_credit\', :play_ms_delta, :coin_delta, '
            . ':remainder_before_ms, :remainder_after_ms, :coin_balance_after, :coin_debt_after, '
            . ':total_play_ms_after, '
            . ':coin_status, \'verification-server\', :reason)'
        );
        $statement->execute([
            'event_id' => Uuid::v4(),
            'event_key' => 'run:' . $score->runId,
            'player_id' => $playerId,
            'run_id' => $score->runId,
            'play_ms_delta' => $creditedPlayMs,
            'coin_delta' => $coinsEarned,
            'remainder_before_ms' => $remainderBefore,
            'remainder_after_ms' => $remainderAfter,
            'coin_balance_after' => $coinBalance,
            'coin_debt_after' => $coinDebt,
            'total_play_ms_after' => $totalPlayMs,
            'coin_status' => $coinStatus,
            'reason' => match ($coinStatus) {
                'eligible' => 'Protocol-verified play time.',
                'withheld' => 'Play time withheld pending security review.',
                default => 'Play time rejected because the event trace was already claimed.',
            },
        ]);
    }

    private function completeAttempt(
        RunProof $proof,
        string $playerId,
        int $serverElapsedMs,
        ScoreSubmission $score,
    ): void {
        $statement = $this->database->prepare(
            "UPDATE run_attempts SET player_id = :player_id, status = 'completed', "
            . 'submitted_at = UTC_TIMESTAMP(3), completed_at = UTC_TIMESTAMP(3), '
            . 'server_elapsed_ms = :server_elapsed_ms, proof_hash = :proof_hash, '
            . 'risk_score = :risk_score, risk_reasons = :risk_reasons, '
            . 'submission_attempts = submission_attempts + 1 WHERE run_id = :run_id'
        );
        $statement->bindValue(':player_id', $playerId);
        $statement->bindValue(':server_elapsed_ms', $serverElapsedMs, PDO::PARAM_INT);
        $statement->bindValue(':proof_hash', $proof->proofHash(), PDO::PARAM_LOB);
        $statement->bindValue(':risk_score', $score->riskScore, PDO::PARAM_INT);
        $statement->bindValue(':risk_reasons', $this->riskReasons($score));
        $statement->bindValue(':run_id', $proof->runId);
        $statement->execute();
    }

    private function rejectAttempt(RunProof $proof, string $code, string $reason): void
    {
        $this->insertProof($proof, 'rejected', mb_strcut($reason, 0, 500, 'UTF-8'));
        $this->updateAttemptStatus($proof->runId, 'rejected', $code, $proof->proofHash());
    }

    private function updateAttemptStatus(
        string $runId,
        string $status,
        string $code,
        ?string $proofHash = null,
    ): void {
        $statement = $this->database->prepare(
            'UPDATE run_attempts SET status = :status, rejection_code = :rejection_code, '
            . 'submitted_at = UTC_TIMESTAMP(3), proof_hash = :proof_hash, '
            . 'submission_attempts = submission_attempts + 1 WHERE run_id = :run_id'
        );
        $statement->bindValue(':status', $status);
        $statement->bindValue(':rejection_code', $code);
        $statement->bindValue(':proof_hash', $proofHash, $proofHash === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $statement->bindValue(':run_id', $runId);
        $statement->execute();
    }

    private function findCompletedRun(string $runId): array|false
    {
        $statement = $this->database->prepare(
            'SELECT player_id, payload_hash, coins_awarded, leaderboard_improved, verification_status '
            . 'FROM completed_runs WHERE run_id = :run_id FOR UPDATE'
        );
        $statement->execute(['run_id' => $runId]);
        return $statement->fetch();
    }

    private function assertMatchingRun(array $existing, string $playerId, RunProof $proof): void
    {
        $storedHash = $existing['payload_hash'] ?? null;
        if (
            !is_string($storedHash)
            || !hash_equals((string) $existing['player_id'], $playerId)
            || !hash_equals($storedHash, $proof->proofHash())
        ) {
            throw new ApiException(409, 'Run ID has already been used for a different result.');
        }
    }

    private function existingRecord(array $existing, array $player): array
    {
        return [
            'improved' => (bool) $existing['leaderboard_improved'],
            'duplicate' => true,
            'verificationStatus' => (string) $existing['verification_status'],
            'coinsEarned' => (int) $existing['coins_awarded'],
            'coinBalance' => (int) $player['coins'],
            'totalPlayMs' => (int) $player['total_play_ms'],
            'verifiedResult' => null,
        ];
    }

    private function riskReasons(ScoreSubmission $score): ?string
    {
        if ($score->riskFlags === []) return null;
        return mb_strcut(
            json_encode($score->riskFlags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            0,
            500,
            'UTF-8',
        );
    }

    private function proofHandledMs(RunProof $proof): int
    {
        $maximum = 0;
        foreach ($proof->events as $event) {
            $handled = match ($event[0]) {
                RunProof::EVENT_HIT, RunProof::EVENT_MISS, RunProof::EVENT_FINISH => $event[2],
                default => $event[1],
            };
            $maximum = max($maximum, (int) $handled);
        }
        return $maximum;
    }

    private function publicScore(ScoreSubmission $score): array
    {
        return [
            'score' => $score->score,
            'hits' => $score->hits,
            'misses' => $score->misses,
            'dodges' => $score->dodges,
            'survivalMs' => $score->survivalMs,
            'fastestReactionMs' => $score->fastestReactionMs,
            'averageReactionMs' => $score->averageReactionMs,
            'speedRatings' => [
                'godlike' => $score->godlikeCount,
                'perfect' => $score->perfectCount,
                'great' => $score->greatCount,
                'good' => $score->goodCount,
            ],
        ];
    }

    private function rollBack(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }
}
