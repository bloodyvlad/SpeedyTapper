<?php

declare(strict_types=1);

namespace SpeedyTapper;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class LeaderboardModerationService
{
    private const ACTIVE_STATUSES = ['legacy', 'verified', 'review'];
    private const ALL_STATUSES = ['legacy', 'verified', 'review', 'quarantined', 'deleted'];
    private const MODERATION_ACTIONS = ['approve', 'reject', 'quarantine', 'restore', 'delete'];
    private const ELIGIBLE_COIN_STATUSES = ['legacy', 'eligible'];
    private const MAX_LIST_LIMIT = 500;
    private const DODGE_POINTS = 550;
    private const MAX_POINTS_PER_HIT = 5_000;
    private const MIN_POINTS_PER_HIT = 100;
    private const MIN_DECOY_GAP_MS = 600;
    private const FIRST_DECOY_AT_MS = 10_000;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEntries(
        ?string $seasonId = null,
        ?string $mode = null,
        ?string $status = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $parameters = [];
        $where = $this->filterWhere($seasonId, $mode, $status, $parameters);
        $limit = $this->validateLimit($limit);
        $offset = $this->validateOffset($offset);
        $statement = $this->database->prepare(
            'SELECT l.id, l.season_id, l.player_id, p.nickname, l.mode, l.score, '
            . 'l.duration_ms, l.correct_taps, l.dodge_count, l.fastest_reaction_ms, '
            . 'l.average_reaction_ms, l.verification_status, l.ruleset_id, l.proof_version, '
            . 'l.risk_score, l.risk_reasons, l.moderated_at, l.moderated_by, '
            . 'l.moderation_reason, l.achieved_at, c.run_id, c.coin_status '
            . 'FROM leaderboard_entries l '
            . 'INNER JOIN players p ON p.id = l.player_id '
            . 'LEFT JOIN completed_runs c ON c.leaderboard_entry_id = l.id '
            . $where
            . ' ORDER BY l.achieved_at DESC, l.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($parameters);
        return array_map([$this, 'normalizeListRow'], $statement->fetchAll());
    }

    /**
     * A conservative read-only audit. Flags are evidence for human review, never an
     * instruction to mutate more than the one exact result selected by an operator.
     *
     * @return array{scanned: int, flagged: int, entries: list<array<string, mixed>>}
     */
    public function scan(
        ?string $seasonId = null,
        ?string $mode = null,
        ?string $status = null,
        int $limit = 200,
        int $offset = 0,
    ): array {
        $parameters = [];
        $where = $this->filterWhere($seasonId, $mode, $status, $parameters);
        $limit = $this->validateLimit($limit);
        $offset = $this->validateOffset($offset);
        $fetchLimit = $limit + 1;
        $statement = $this->database->prepare(
            'SELECT l.*, p.nickname, c.run_id, c.mode AS run_mode, c.score AS run_score, '
            . 'c.duration_ms AS run_duration_ms, c.reaction_base_points, '
            . 'c.multiplier_bonus_points, c.coins_awarded, c.verification_status AS run_status, '
            . 'c.coin_status, c.player_id AS run_player_id '
            . 'FROM leaderboard_entries l '
            . 'INNER JOIN players p ON p.id = l.player_id '
            . 'LEFT JOIN completed_runs c ON c.leaderboard_entry_id = l.id '
            . $where
            . ' ORDER BY l.score DESC, l.achieved_at ASC, l.id ASC LIMIT ' . $fetchLimit
            . ' OFFSET ' . $offset
        );
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $flagged = [];
        foreach ($rows as $row) {
            $flags = $this->auditFlags($row);
            if ($flags === []) {
                continue;
            }
            $entry = $this->normalizeListRow($row);
            $entry['flags'] = $flags;
            $entry['highestSeverity'] = $this->highestSeverity($flags);
            $flagged[] = $entry;
        }

        return [
            'scanned' => count($rows),
            'flagged' => count($flagged),
            'entries' => $flagged,
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => $hasMore,
        ];
    }

    /** @return array<string, mixed> */
    public function showForAdmin(string $entryId): array
    {
        $detail = $this->show($entryId);
        if (is_array($detail['entry'] ?? null) && is_string($detail['entry']['player_id'] ?? null)) {
            $detail['entry']['playerId'] = $detail['entry']['player_id'];
        }
        foreach (['completedRun', 'runAttempt'] as $section) {
            if (!is_array($detail[$section] ?? null)) {
                continue;
            }
            foreach (['payload_hash_hex', 'proof_hash_hex', 'session_binding_hash_hex'] as $field) {
                unset($detail[$section][$field]);
            }
        }
        if (is_array($detail['runProof'] ?? null)) {
            unset($detail['runProof']['payload_hash_hex'], $detail['runProof']['trace_hash_hex']);
        }
        return $detail;
    }

    /** @return array<string, mixed> */
    public function show(string $entryId): array
    {
        $entryId = $this->validateEntryId($entryId);
        $statement = $this->database->prepare(
            'SELECT l.*, p.nickname, p.coins, p.coin_debt, p.coin_time_remainder_ms, p.total_play_ms '
            . 'FROM leaderboard_entries l INNER JOIN players p ON p.id = l.player_id '
            . 'WHERE l.id = :entry_id LIMIT 1'
        );
        $statement->execute(['entry_id' => $entryId]);
        $entry = $statement->fetch();
        if (!is_array($entry)) {
            throw new ApiException(404, 'Leaderboard entry was not found.');
        }

        $run = $this->linkedCompletedRun($entryId, false);
        $attempt = null;
        $proof = null;
        if (is_array($run)) {
            $attemptStatement = $this->database->prepare(
                'SELECT * FROM run_attempts WHERE run_id = :run_id LIMIT 1'
            );
            $attemptStatement->execute(['run_id' => $run['run_id']]);
            $attemptRow = $attemptStatement->fetch();
            $attempt = is_array($attemptRow) ? $this->normalizeBinaryHashes($attemptRow) : null;

            $proofStatement = $this->database->prepare(
                'SELECT run_id, proof_version, event_count, HEX(payload_hash) AS payload_hash_hex, '
                . 'HEX(trace_hash) AS trace_hash_hex, '
                . 'validation_status, validation_reason, created_at, validated_at '
                . 'FROM run_proofs WHERE run_id = :run_id LIMIT 1'
            );
            $proofStatement->execute(['run_id' => $run['run_id']]);
            $proofRow = $proofStatement->fetch();
            $proof = is_array($proofRow) ? $proofRow : null;
        }

        $events = $this->database->prepare(
            'SELECT * FROM leaderboard_moderation_events '
            . 'WHERE leaderboard_entry_id = :entry_id ORDER BY created_at ASC, event_id ASC'
        );
        $events->execute(['entry_id' => $entryId]);

        $ledger = $this->database->prepare(
            'SELECT * FROM coin_ledger WHERE player_id = :player_id '
            . 'AND (run_id = :run_id OR :missing_run_id IS NULL) '
            . 'ORDER BY created_at ASC, event_id ASC'
        );
        $ledger->bindValue(':player_id', (string) $entry['player_id']);
        $ledger->bindValue(':run_id', is_array($run) ? (string) $run['run_id'] : null);
        $ledger->bindValue(':missing_run_id', is_array($run) ? (string) $run['run_id'] : null);
        $ledger->execute();

        return [
            'entry' => $this->normalizeNumericFields($entry),
            'completedRun' => is_array($run) ? $this->normalizeBinaryHashes($run) : null,
            'runAttempt' => $attempt,
            'runProof' => $proof,
            'moderationEvents' => array_map(
                [$this, 'decodeJsonFields'],
                $events->fetchAll(),
            ),
            'coinLedger' => $ledger->fetchAll(),
            'auditFlags' => $this->auditFlags(array_merge($entry, is_array($run) ? [
                'run_id' => $run['run_id'],
                'run_mode' => $run['mode'],
                'run_score' => $run['score'],
                'run_duration_ms' => $run['duration_ms'],
                'reaction_base_points' => $run['reaction_base_points'],
                'multiplier_bonus_points' => $run['multiplier_bonus_points'],
                'coins_awarded' => $run['coins_awarded'],
                'run_status' => $run['verification_status'],
                'coin_status' => $run['coin_status'],
                'run_player_id' => $run['player_id'],
            ] : ['run_id' => null])),
        ];
    }

    /** @return array<string, mixed> */
    public function transition(
        string $entryId,
        string $action,
        string $actor,
        string $reason,
        bool $apply = false,
        ?string $expectedStatus = null,
        ?string $actorPlayerId = null,
    ): array {
        $entryId = $this->validateEntryId($entryId);
        if (!in_array($action, self::MODERATION_ACTIONS, true)) {
            throw new InvalidArgumentException('Moderation action is invalid.');
        }
        [$actor, $reason] = $this->validateAuditText($actor, $reason);

        $this->database->beginTransaction();
        try {
            $targetPlayerId = $this->lockEntryPlayer($entryId);
            $entry = $this->lockEntry($entryId);
            $fromStatus = (string) $entry['verification_status'];
            if ($expectedStatus !== null && !hash_equals($expectedStatus, $fromStatus)) {
                throw new ApiException(409, 'The result changed since it was loaded. Refresh and review it again.');
            }
            if ($actorPlayerId !== null) {
                $this->assertAdminActor($actorPlayerId);
            }
            $toStatus = match ($action) {
                'approve' => $this->reviewStatus($fromStatus, 'verified'),
                'reject' => $this->reviewStatus($fromStatus, 'quarantined'),
                'quarantine' => 'quarantined',
                'delete' => $this->deleteStatus($fromStatus),
                'restore' => $this->restoreStatus($entryId, $fromStatus),
            };
            if ($action === 'quarantine' && $fromStatus === 'deleted') {
                throw new ApiException(409, 'A deleted result must be restored before it can be quarantined.');
            }

            $run = $this->linkedCompletedRun($entryId, true);
            $fromCoinStatus = is_array($run) ? (string) $run['coin_status'] : null;
            $toCoinStatus = is_array($run) ? $this->coinStatusFor($toStatus) : null;
            if ($fromStatus === $toStatus && $fromCoinStatus === $toCoinStatus) {
                $this->database->rollBack();
                return [
                    'applied' => false,
                    'dryRun' => !$apply,
                    'noOp' => true,
                    'entryId' => $entryId,
                    'status' => $fromStatus,
                    'message' => 'The result is already in the requested state.',
                ];
            }

            $eventId = Uuid::v4();
            $updateEntry = $this->database->prepare(
                'UPDATE leaderboard_entries SET verification_status = :status, '
                . 'moderated_at = UTC_TIMESTAMP(3), moderated_by = :actor, '
                . 'moderation_reason = :reason WHERE id = :entry_id'
            );
            $updateEntry->execute([
                'status' => $toStatus,
                'actor' => $actor,
                'reason' => $reason,
                'entry_id' => $entryId,
            ]);

            $coinResult = null;
            if (is_array($run)) {
                if (!hash_equals((string) $entry['player_id'], (string) $run['player_id'])) {
                    throw new RuntimeException('Linked completed run belongs to another player.');
                }
                $updateRun = $this->database->prepare(
                    'UPDATE completed_runs SET verification_status = :status, coin_status = :coin_status, '
                    . 'moderated_at = UTC_TIMESTAMP(3), moderated_by = :actor, moderation_reason = :reason '
                    . 'WHERE run_id = :run_id'
                );
                $updateRun->execute([
                    'status' => $toStatus,
                    'coin_status' => $toCoinStatus,
                    'actor' => $actor,
                    'reason' => $reason,
                    'run_id' => $run['run_id'],
                ]);
                $coinResult = $this->recomputePlayerCoins(
                    (string) $entry['player_id'],
                    (string) $run['run_id'],
                    $eventId,
                    'moderation_reconcile',
                    $toCoinStatus,
                    $actor,
                    $reason,
                );
            }

            $details = [
                'linkedRun' => is_array($run),
                'coinReconciliation' => $coinResult,
                'warning' => is_array($run)
                    ? null
                    : 'No strictly linked completed run exists, so coin totals were not changed.',
            ];
            $insertEvent = $this->database->prepare(
                'INSERT INTO leaderboard_moderation_events '
                . '(event_id, leaderboard_entry_id, completed_run_id, player_id, action, '
                . 'from_status, to_status, from_coin_status, to_coin_status, actor, reason, details_json) '
                . 'VALUES (:event_id, :entry_id, :run_id, :player_id, :action, :from_status, '
                . ':to_status, :from_coin_status, :to_coin_status, :actor, :reason, :details_json)'
            );
            $insertEvent->execute([
                'event_id' => $eventId,
                'entry_id' => $entryId,
                'run_id' => is_array($run) ? $run['run_id'] : null,
                'player_id' => $entry['player_id'],
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'from_coin_status' => $fromCoinStatus,
                'to_coin_status' => $toCoinStatus,
                'actor' => $actor,
                'reason' => $reason,
                'details_json' => json_encode($details, JSON_THROW_ON_ERROR),
            ]);

            $result = [
                'applied' => $apply,
                'dryRun' => !$apply,
                'noOp' => false,
                'eventId' => $eventId,
                'entryId' => $entryId,
                'playerId' => (string) $entry['player_id'],
                'runId' => is_array($run) ? (string) $run['run_id'] : null,
                'action' => $action,
                'fromStatus' => $fromStatus,
                'toStatus' => $toStatus,
                'fromCoinStatus' => $fromCoinStatus,
                'toCoinStatus' => $toCoinStatus,
                'coinReconciliation' => $coinResult,
                'warning' => $details['warning'],
            ];
            if ($apply) {
                $this->database->commit();
            } else {
                $this->database->rollBack();
            }
            return $result;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function deleteAndReset(
        string $entryId,
        string $actorPlayerId,
        string $reason,
        string $expectedStatus = 'quarantined',
        mixed $confirmPlayerId = null,
    ): array {
        $entryId = $this->validateEntryId($entryId);
        [, $reason] = $this->validateAuditText('admin:' . $actorPlayerId, $reason);
        if (!hash_equals('quarantined', $expectedStatus)) {
            throw new ApiException(409, 'A result must be reviewed and quarantined before deletion.');
        }
        if (!is_string($confirmPlayerId) || !Uuid::isValidV4(strtolower(trim($confirmPlayerId)))) {
            throw new ApiException(400, 'Confirm the exact affected player before resetting rewards.');
        }
        $confirmPlayerId = strtolower(trim($confirmPlayerId));

        $this->database->beginTransaction();
        try {
            $targetPlayerId = $this->lockEntryPlayer($entryId);
            $entry = $this->lockEntry($entryId);
            $this->assertAdminActor($actorPlayerId);
            if (!hash_equals($confirmPlayerId, $targetPlayerId)) {
                throw new ApiException(409, 'The selected player changed. Refresh and review the result again.');
            }

            $existingReset = $this->rewardResetForEntry($entryId, true);
            if (is_array($existingReset)) {
                $this->database->commit();
                return [
                    'applied' => false,
                    'duplicate' => true,
                    ...$this->normalizeRewardReset($existingReset),
                ];
            }

            $fromStatus = (string) $entry['verification_status'];
            if (!hash_equals($expectedStatus, $fromStatus)) {
                throw new ApiException(409, 'The result changed since it was loaded. Refresh and review it again.');
            }

            $run = $this->linkedCompletedRun($entryId, true);
            if (is_array($run) && !hash_equals($targetPlayerId, (string) $run['player_id'])) {
                throw new RuntimeException('Linked completed run belongs to another player.');
            }
            $fromCoinStatus = is_array($run) ? (string) $run['coin_status'] : null;
            $runId = is_array($run) ? (string) $run['run_id'] : null;

            $playerStatement = $this->database->prepare(
                'SELECT coins, coin_debt, earned_coins, purchased_coins, earned_coin_debt, '
                . 'refund_coin_debt, total_coins_collected, coin_time_remainder_ms, '
                . 'total_play_ms, economy_generation FROM players '
                . 'WHERE id = :player_id FOR UPDATE'
            );
            $playerStatement->execute(['player_id' => $targetPlayerId]);
            $player = $playerStatement->fetch();
            if (!is_array($player)) {
                throw new RuntimeException('Player was not found for reward reset.');
            }
            $fromGeneration = (int) $player['economy_generation'];
            if ($fromGeneration >= 4_294_967_295) {
                throw new RuntimeException('Player economy generation cannot be advanced.');
            }
            $toGeneration = $fromGeneration + 1;
            $resetId = Uuid::v4();
            $refundDebtReopened = $this->syncEarnedRefundDebtSettlements(
                $targetPlayerId,
                'admin-reset:' . $resetId,
                $toGeneration,
                'revoked',
            );
            $refundDebtAfter = (int) $player['refund_coin_debt'] + $refundDebtReopened;
            if ($refundDebtAfter < 0) {
                throw new RuntimeException('Reward reset refund-debt provenance underflowed.');
            }

            $removedCosmetics = $this->removeUnpaidCosmetics($targetPlayerId);
            $petIds = $removedCosmetics['petIds'];
            $petsRemoved = count($petIds);
            $selectionsRemoved = $removedCosmetics['petSelectionsRemoved'];
            $themeIds = $removedCosmetics['themeIds'];
            $themesRemoved = count($themeIds);
            $themeSelectionsRemoved = $removedCosmetics['themeSelectionsRemoved'];

            $abandonAttempts = $this->database->prepare(
                "UPDATE run_attempts SET status = 'abandoned', rejection_code = 'admin-reward-reset', "
                . 'updated_at = UTC_TIMESTAMP(3) WHERE player_id = :player_id '
                . "AND status = 'issued'"
            );
            $abandonAttempts->execute(['player_id' => $targetPlayerId]);
            $attemptsAbandoned = $abandonAttempts->rowCount();

            $actor = 'admin:' . strtolower(trim($actorPlayerId));
            $updateEntry = $this->database->prepare(
                "UPDATE leaderboard_entries SET verification_status = 'deleted', "
                . 'moderated_at = UTC_TIMESTAMP(3), moderated_by = :actor, '
                . 'moderation_reason = :reason WHERE id = :entry_id'
            );
            $updateEntry->execute([
                'actor' => $actor,
                'reason' => $reason,
                'entry_id' => $entryId,
            ]);
            if (is_array($run)) {
                $updateRun = $this->database->prepare(
                    "UPDATE completed_runs SET verification_status = 'deleted', coin_status = 'revoked', "
                    . 'moderated_at = UTC_TIMESTAMP(3), moderated_by = :actor, '
                    . 'moderation_reason = :reason WHERE run_id = :run_id'
                );
                $updateRun->execute([
                    'actor' => $actor,
                    'reason' => $reason,
                    'run_id' => $runId,
                ]);
            }

            $coinsRemoved = (int) $player['earned_coins'];
            $debtCleared = (int) $player['earned_coin_debt'];
            $remainderRemoved = (int) $player['coin_time_remainder_ms'];
            $totalPlayRemoved = (int) $player['total_play_ms'];
            $totalCollectedRemoved = (int) $player['total_coins_collected'];

            $resetLedger = $this->database->prepare(
                'INSERT INTO coin_ledger '
                . '(event_id, event_key, player_id, economy_generation, run_id, event_type, '
                . 'play_ms_delta, coin_delta, remainder_before_ms, remainder_after_ms, '
                . 'earned_delta, purchased_delta, coin_balance_after, earned_balance_after, '
                . 'purchased_balance_after, coin_debt_after, earned_debt_after, refund_debt_after, '
                . 'total_play_ms_after, coin_status, actor, reason) '
                . 'VALUES (:event_id, :event_key, :player_id, :economy_generation, :run_id, '
                . "'admin_reward_reset', :play_ms_delta, :coin_delta, :remainder_before_ms, 0, "
                . ':earned_delta, 0, :coin_balance_after, 0, :purchased_balance_after, '
                . ":coin_debt_after, 0, :refund_debt_after, 0, 'revoked', :actor, :reason)"
            );
            $resetLedger->execute([
                'event_id' => Uuid::v4(),
                'event_key' => 'admin-reset:' . $resetId,
                'player_id' => $targetPlayerId,
                'economy_generation' => $fromGeneration,
                'run_id' => $runId,
                'play_ms_delta' => -$totalPlayRemoved,
                'coin_delta' => -$coinsRemoved + $debtCleared,
                'earned_delta' => -$coinsRemoved + $debtCleared,
                'remainder_before_ms' => $remainderRemoved,
                'coin_balance_after' => (int) $player['purchased_coins'],
                'purchased_balance_after' => (int) $player['purchased_coins'],
                'coin_debt_after' => $refundDebtAfter,
                'refund_debt_after' => $refundDebtAfter,
                'actor' => $actor,
                'reason' => $reason,
            ]);

            $updatePlayer = $this->database->prepare(
                'UPDATE players SET earned_coins = 0, earned_coin_debt = 0, '
                . 'refund_coin_debt = :refund_coin_debt, coins = purchased_coins, '
                . 'coin_debt = :coin_debt, total_coins_collected = 0, '
                . 'coin_time_remainder_ms = 0, total_play_ms = 0, '
                . 'economy_generation = :economy_generation, updated_at = UTC_TIMESTAMP(3) '
                . 'WHERE id = :player_id'
            );
            $updatePlayer->execute([
                'refund_coin_debt' => $refundDebtAfter,
                'coin_debt' => $refundDebtAfter,
                'economy_generation' => $toGeneration,
                'player_id' => $targetPlayerId,
            ]);

            $petIdsJson = json_encode($petIds, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $themeIdsJson = json_encode($themeIds, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $insertReset = $this->database->prepare(
                'INSERT INTO account_reward_resets '
                . '(reset_id, trigger_entry_id, player_id, actor_player_id, from_generation, '
                . 'to_generation, coins_removed, debt_cleared, remainder_removed_ms, '
                . 'total_play_removed_ms, total_collected_removed, pets_removed, pet_ids_json, '
                . 'themes_removed, theme_ids_json, reason) '
                . 'VALUES (:reset_id, :trigger_entry_id, :player_id, :actor_player_id, '
                . ':from_generation, :to_generation, :coins_removed, :debt_cleared, '
                . ':remainder_removed_ms, :total_play_removed_ms, :total_collected_removed, '
                . ':pets_removed, :pet_ids_json, :themes_removed, :theme_ids_json, :reason)'
            );
            $insertReset->execute([
                'reset_id' => $resetId,
                'trigger_entry_id' => $entryId,
                'player_id' => $targetPlayerId,
                'actor_player_id' => $actorPlayerId,
                'from_generation' => $fromGeneration,
                'to_generation' => $toGeneration,
                'coins_removed' => $coinsRemoved,
                'debt_cleared' => $debtCleared,
                'remainder_removed_ms' => $remainderRemoved,
                'total_play_removed_ms' => $totalPlayRemoved,
                'total_collected_removed' => $totalCollectedRemoved,
                'pets_removed' => $petsRemoved,
                'pet_ids_json' => $petIdsJson,
                'themes_removed' => $themesRemoved,
                'theme_ids_json' => $themeIdsJson,
                'reason' => $reason,
            ]);

            $details = [
                'rewardResetId' => $resetId,
                'fromGeneration' => $fromGeneration,
                'toGeneration' => $toGeneration,
                'coinsRemoved' => $coinsRemoved,
                'debtCleared' => $debtCleared,
                'refundDebtReopened' => $refundDebtReopened,
                'remainderRemovedMs' => $remainderRemoved,
                'totalPlayRemovedMs' => $totalPlayRemoved,
                'totalCollectedRemoved' => $totalCollectedRemoved,
                'petsRemoved' => $petsRemoved,
                'petIds' => $petIds,
                'selectionsRemoved' => $selectionsRemoved,
                'themesRemoved' => $themesRemoved,
                'themeIds' => $themeIds,
                'themeSelectionsRemoved' => $themeSelectionsRemoved,
                'attemptsAbandoned' => $attemptsAbandoned,
                'achievementsPreserved' => true,
                'linkedRunRevoked' => is_array($run),
            ];
            $eventId = Uuid::v4();
            $insertEvent = $this->database->prepare(
                'INSERT INTO leaderboard_moderation_events '
                . '(event_id, leaderboard_entry_id, completed_run_id, player_id, action, '
                . 'from_status, to_status, from_coin_status, to_coin_status, actor, reason, details_json) '
                . "VALUES (:event_id, :entry_id, :run_id, :player_id, 'delete_reset', "
                . ":from_status, 'deleted', :from_coin_status, :to_coin_status, :actor, :reason, :details_json)"
            );
            $insertEvent->execute([
                'event_id' => $eventId,
                'entry_id' => $entryId,
                'run_id' => $runId,
                'player_id' => $targetPlayerId,
                'from_status' => $fromStatus,
                'from_coin_status' => $fromCoinStatus,
                'to_coin_status' => is_array($run) ? 'revoked' : null,
                'actor' => $actor,
                'reason' => $reason,
                'details_json' => json_encode($details, JSON_THROW_ON_ERROR),
            ]);

            $this->database->commit();
            return [
                'applied' => true,
                'duplicate' => false,
                'entryId' => $entryId,
                'playerId' => $targetPlayerId,
                'runId' => $runId,
                'status' => 'deleted',
                ...$details,
            ];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Remove only cosmetics whose active debit has no purchased-lot allocation.
     * Mixed-funded and fully paid cosmetics are retained during moderation.
     *
     * @return array{petIds: list<string>, themeIds: list<string>, petSelectionsRemoved: int, themeSelectionsRemoved: int}
     */
    private function removeUnpaidCosmetics(string $playerId): array
    {
        $find = function (string $table, string $column) use ($playerId): array {
            $statement = $this->database->prepare(
                'SELECT owned.' . $column . ' FROM ' . $table . ' owned '
                . 'WHERE owned.player_id = :player_id AND NOT EXISTS ('
                . 'SELECT 1 FROM coin_spend_allocations allocation '
                . 'WHERE allocation.spend_event_id = owned.purchase_event_id '
                . "AND allocation.source = 'purchased' AND allocation.released_at IS NULL"
                . ') ORDER BY owned.' . $column . ' FOR UPDATE'
            );
            $statement->execute(['player_id' => $playerId]);
            return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        };
        $delete = function (string $table, string $column, array $ids) use ($playerId): void {
            if ($ids === []) return;
            $placeholders = [];
            $parameters = ['player_id' => $playerId];
            foreach ($ids as $index => $id) {
                $key = 'item_' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = $id;
            }
            $statement = $this->database->prepare(
                'DELETE FROM ' . $table . ' WHERE player_id = :player_id AND '
                . $column . ' IN (' . implode(',', $placeholders) . ')'
            );
            $statement->execute($parameters);
        };

        $selectedPet = $this->database->prepare(
            'SELECT pet_id FROM player_pet_selection WHERE player_id = :player_id FOR UPDATE'
        );
        $selectedPet->execute(['player_id' => $playerId]);
        $selectedPetId = $selectedPet->fetchColumn();
        $petIds = $find('player_pets', 'pet_id');
        $petSelectionsRemoved = is_string($selectedPetId) && in_array($selectedPetId, $petIds, true) ? 1 : 0;
        $delete('player_pets', 'pet_id', $petIds);

        $selectedTheme = $this->database->prepare(
            'SELECT theme_id FROM player_theme_selection WHERE player_id = :player_id FOR UPDATE'
        );
        $selectedTheme->execute(['player_id' => $playerId]);
        $selectedThemeId = $selectedTheme->fetchColumn();
        $themeIds = $find('player_themes', 'theme_id');
        $themeSelectionsRemoved = is_string($selectedThemeId)
            && in_array($selectedThemeId, $themeIds, true) ? 1 : 0;
        if ($themeSelectionsRemoved === 1) {
            $this->database->prepare(
                'DELETE FROM player_theme_selection WHERE player_id = :player_id'
            )->execute(['player_id' => $playerId]);
        }
        $delete('player_themes', 'theme_id', $themeIds);

        return compact(
            'petIds',
            'themeIds',
            'petSelectionsRemoved',
            'themeSelectionsRemoved',
        );
    }

    /** @return array<string, mixed> */
    public function reconcile(
        string $entryId,
        string $actor,
        string $reason,
        bool $apply = false,
    ): array {
        $entryId = $this->validateEntryId($entryId);
        [$actor, $reason] = $this->validateAuditText($actor, $reason);
        $this->database->beginTransaction();
        try {
            $this->lockEntryPlayer($entryId);
            $entry = $this->lockEntry($entryId);
            $run = $this->linkedCompletedRun($entryId, true);
            if (!is_array($run)) {
                throw new RuntimeException(
                    'This entry has no strictly linked completed run; coin reconciliation was refused.'
                );
            }
            if (!hash_equals((string) $entry['player_id'], (string) $run['player_id'])) {
                throw new RuntimeException('Linked completed run belongs to another player.');
            }

            $eventId = Uuid::v4();
            $coinResult = $this->recomputePlayerCoins(
                (string) $entry['player_id'],
                (string) $run['run_id'],
                $eventId,
                'manual_reconcile',
                (string) $run['coin_status'],
                $actor,
                $reason,
            );
            $details = ['coinReconciliation' => $coinResult];
            $event = $this->database->prepare(
                'INSERT INTO leaderboard_moderation_events '
                . '(event_id, leaderboard_entry_id, completed_run_id, player_id, action, '
                . 'from_status, to_status, from_coin_status, to_coin_status, actor, reason, details_json) '
                . 'VALUES (:event_id, :entry_id, :run_id, :player_id, :action, :from_status, '
                . ':to_status, :from_coin_status, :to_coin_status, :actor, :reason, :details_json)'
            );
            $event->execute([
                'event_id' => $eventId,
                'entry_id' => $entryId,
                'run_id' => $run['run_id'],
                'player_id' => $entry['player_id'],
                'action' => 'reconcile',
                'from_status' => $entry['verification_status'],
                'to_status' => $entry['verification_status'],
                'from_coin_status' => $run['coin_status'],
                'to_coin_status' => $run['coin_status'],
                'actor' => $actor,
                'reason' => $reason,
                'details_json' => json_encode($details, JSON_THROW_ON_ERROR),
            ]);

            $result = [
                'applied' => $apply,
                'dryRun' => !$apply,
                'eventId' => $eventId,
                'entryId' => $entryId,
                'playerId' => (string) $entry['player_id'],
                'runId' => (string) $run['run_id'],
                'coinReconciliation' => $coinResult,
            ];
            if ($apply) {
                $this->database->commit();
            } else {
                $this->database->rollBack();
            }
            return $result;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function filterWhere(
        ?string $seasonId,
        ?string $mode,
        ?string $status,
        array &$parameters,
    ): string {
        $clauses = [];
        if ($seasonId !== null) {
            if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $seasonId) !== 1) {
                throw new InvalidArgumentException('Season ID is invalid.');
            }
            $clauses[] = 'l.season_id = :season_id';
            $parameters['season_id'] = $seasonId;
        }
        if ($mode !== null) {
            if ($mode !== 'normal' && $mode !== 'zen') {
                throw new InvalidArgumentException('Mode must be normal or zen.');
            }
            $clauses[] = 'l.mode = :mode';
            $parameters['mode'] = $mode;
        }
        if ($status === null) {
            $clauses[] = "l.verification_status <> 'deleted'";
        } else {
            if (!in_array($status, self::ALL_STATUSES, true)) {
                throw new InvalidArgumentException('Verification status is invalid.');
            }
            $clauses[] = 'l.verification_status = :verification_status';
            $parameters['verification_status'] = $status;
        }
        return $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
    }

    private function validateLimit(int $limit): int
    {
        if ($limit < 1 || $limit > self::MAX_LIST_LIMIT) {
            throw new InvalidArgumentException('Limit must be between 1 and ' . self::MAX_LIST_LIMIT . '.');
        }
        return $limit;
    }

    private function validateOffset(int $offset): int
    {
        if ($offset < 0 || $offset > 10_000_000) {
            throw new InvalidArgumentException('Offset must be between 0 and 10000000.');
        }
        return $offset;
    }

    private function validateEntryId(string $entryId): string
    {
        $normalized = strtolower(trim($entryId));
        if (!Uuid::isValidV4($normalized)) {
            throw new InvalidArgumentException('An exact version-4 leaderboard entry UUID is required.');
        }
        return $normalized;
    }

    /** @return array{string, string} */
    private function validateAuditText(string $actor, string $reason): array
    {
        $actor = trim($actor);
        $reason = trim($reason);
        if ($actor === '' || mb_strlen($actor, 'UTF-8') > 80) {
            throw new InvalidArgumentException('Actor is required and must be at most 80 characters.');
        }
        if (mb_strlen($reason, 'UTF-8') < 8 || mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('Reason must contain 8 to 500 characters.');
        }
        return [$actor, $reason];
    }

    /** @return array<string, mixed> */
    private function lockEntry(string $entryId): array
    {
        $statement = $this->database->prepare(
            'SELECT id, player_id, verification_status FROM leaderboard_entries '
            . 'WHERE id = :entry_id FOR UPDATE'
        );
        $statement->execute(['entry_id' => $entryId]);
        $entry = $statement->fetch();
        if (!is_array($entry)) {
            throw new ApiException(404, 'Leaderboard entry was not found.');
        }
        return $entry;
    }

    private function lockEntryPlayer(string $entryId): string
    {
        // Discover the immutable owner, then take the same player-first lock
        // order used by score submission before locking the entry itself.
        $owner = $this->database->prepare(
            'SELECT player_id FROM leaderboard_entries WHERE id = :entry_id LIMIT 1'
        );
        $owner->execute(['entry_id' => $entryId]);
        $playerId = $owner->fetchColumn();
        if (!is_string($playerId)) {
            throw new ApiException(404, 'Leaderboard entry was not found.');
        }
        $player = $this->database->prepare(
            'SELECT id FROM players WHERE id = :player_id FOR UPDATE'
        );
        $player->execute(['player_id' => $playerId]);
        if ($player->fetchColumn() === false) {
            throw new RuntimeException('Player was not found for moderation.');
        }
        return $playerId;
    }

    private function assertAdminActor(string $actorPlayerId): void
    {
        $actorPlayerId = strtolower(trim($actorPlayerId));
        if (!Uuid::isValidV4($actorPlayerId)) {
            throw new ApiException(403, 'Administrator session is invalid.');
        }
        $actor = $this->database->prepare(
            "SELECT 1 FROM player_roles WHERE player_id = :player_id "
            . "AND role = 'leaderboard_admin' LIMIT 1"
        );
        $actor->execute(['player_id' => $actorPlayerId]);
        if ($actor->fetchColumn() === false) {
            throw new ApiException(403, 'Leaderboard administrator access is required.');
        }
    }

    /** @return array<string, mixed>|null */
    private function linkedCompletedRun(string $entryId, bool $forUpdate): ?array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM completed_runs WHERE leaderboard_entry_id = :entry_id LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['entry_id' => $entryId]);
        $run = $statement->fetch();
        return is_array($run) ? $run : null;
    }

    /** @return array<string, mixed>|null */
    private function rewardResetForEntry(string $entryId, bool $forUpdate): ?array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM account_reward_resets WHERE trigger_entry_id = :entry_id LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['entry_id' => $entryId]);
        $reset = $statement->fetch();
        return is_array($reset) ? $reset : null;
    }

    /** @param array<string, mixed> $reset */
    private function normalizeRewardReset(array $reset): array
    {
        $petIds = [];
        if (is_string($reset['pet_ids_json'] ?? null)) {
            $decoded = json_decode($reset['pet_ids_json'], true);
            $petIds = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }
        $themeIds = [];
        if (is_string($reset['theme_ids_json'] ?? null)) {
            $decoded = json_decode($reset['theme_ids_json'], true);
            $themeIds = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }
        return [
            'rewardResetId' => (string) $reset['reset_id'],
            'entryId' => (string) $reset['trigger_entry_id'],
            'playerId' => (string) $reset['player_id'],
            'fromGeneration' => (int) $reset['from_generation'],
            'toGeneration' => (int) $reset['to_generation'],
            'coinsRemoved' => (int) $reset['coins_removed'],
            'debtCleared' => (int) $reset['debt_cleared'],
            'remainderRemovedMs' => (int) $reset['remainder_removed_ms'],
            'totalPlayRemovedMs' => (int) $reset['total_play_removed_ms'],
            'totalCollectedRemoved' => (int) $reset['total_collected_removed'],
            'petsRemoved' => (int) $reset['pets_removed'],
            'petIds' => $petIds,
            'themesRemoved' => (int) ($reset['themes_removed'] ?? 0),
            'themeIds' => $themeIds,
            'status' => 'deleted',
        ];
    }

    private function restoreStatus(string $entryId, string $currentStatus): string
    {
        if ($currentStatus !== 'quarantined' && $currentStatus !== 'deleted') {
            throw new ApiException(409, 'Only quarantined or logically deleted results can be restored.');
        }
        $statement = $this->database->prepare(
            "SELECT from_status FROM leaderboard_moderation_events "
            . "WHERE leaderboard_entry_id = :entry_id AND action IN ('reject','quarantine','delete') "
            . 'AND to_status = :current_status '
            . 'ORDER BY created_at DESC, event_id DESC LIMIT 1'
        );
        $statement->execute([
            'entry_id' => $entryId,
            'current_status' => $currentStatus,
        ]);
        $status = $statement->fetchColumn();
        if (
            !is_string($status)
            || !in_array($status, self::ALL_STATUSES, true)
            || $status === $currentStatus
            || $status === 'deleted'
        ) {
            throw new ApiException(409, 'No audited pre-moderation state is available for restore.');
        }
        return $status;
    }

    private function reviewStatus(string $currentStatus, string $targetStatus): string
    {
        if ($currentStatus !== 'review') {
            throw new ApiException(409, 'Only a result held for review can be approved or rejected.');
        }
        return $targetStatus;
    }

    private function deleteStatus(string $currentStatus): string
    {
        if ($currentStatus !== 'quarantined') {
            throw new ApiException(409, 'A result must be quarantined before logical deletion.');
        }
        return 'deleted';
    }

    private function coinStatusFor(string $verificationStatus): string
    {
        return match ($verificationStatus) {
            'verified' => 'eligible',
            'legacy' => 'legacy',
            'review' => 'withheld',
            'quarantined', 'deleted' => 'revoked',
            default => throw new RuntimeException('Verification status has no coin policy.'),
        };
    }

    /** @return array<string, int> */
    private function recomputePlayerCoins(
        string $playerId,
        string $runId,
        string $eventId,
        string $eventType,
        string $coinStatus,
        string $actor,
        string $reason,
    ): array {
        $playerStatement = $this->database->prepare(
            'SELECT coins, coin_debt, earned_coins, purchased_coins, earned_coin_debt, '
            . 'refund_coin_debt, total_coins_collected, coin_time_remainder_ms, total_play_ms, '
            . 'economy_generation FROM players '
            . 'WHERE id = :player_id FOR UPDATE'
        );
        $playerStatement->execute(['player_id' => $playerId]);
        $player = $playerStatement->fetch();
        if (!is_array($player)) {
            throw new RuntimeException('Player was not found for coin reconciliation.');
        }
        $oldWallet = CoinEconomy::summary(
            (int) $player['earned_coins'],
            (int) $player['purchased_coins'],
            (int) $player['earned_coin_debt'],
            (int) $player['refund_coin_debt'],
        );
        $economyGeneration = (int) $player['economy_generation'];
        $refundDebtDelta = $this->syncEarnedRefundDebtSettlements(
            $playerId,
            $runId,
            $economyGeneration,
            $coinStatus,
        );
        $player['refund_coin_debt'] = (int) $player['refund_coin_debt'] + $refundDebtDelta;
        if ((int) $player['refund_coin_debt'] < 0) {
            throw new RuntimeException('Refund-debt settlement moderation underflowed.');
        }

        $eligible = $this->database->prepare(
            "SELECT COALESCE(SUM(CASE "
            . "WHEN verification_status = 'legacy' THEN duration_ms "
            . 'ELSE COALESCE(credited_play_ms, LEAST(duration_ms, COALESCE(server_elapsed_ms, duration_ms))) '
            . 'END), 0) AS eligible_play_ms, '
            . "COALESCE(SUM(CASE WHEN verification_status = 'verified' AND coin_status = 'eligible' "
            . 'THEN COALESCE(credited_play_ms, LEAST(duration_ms, COALESCE(server_elapsed_ms, duration_ms))) '
            . 'ELSE 0 END), 0) AS verified_play_ms '
            . 'FROM completed_runs '
            . "WHERE player_id = :player_id AND economy_generation = :economy_generation "
            . "AND coin_status IN ('legacy','eligible')"
        );
        $eligible->execute([
            'player_id' => $playerId,
            'economy_generation' => $economyGeneration,
        ]);
        $playTime = $eligible->fetch() ?: [];
        $totalPlayMs = (int) ($playTime['eligible_play_ms'] ?? 0);
        $verifiedPlayMs = (int) ($playTime['verified_play_ms'] ?? 0);

        $economyStatement = $this->database->prepare(
            'SELECT '
            . '(SELECT COALESCE(SUM(ledger.earned_delta), 0) FROM coin_ledger ledger '
            . 'WHERE ledger.player_id = :achievement_player_id '
            . 'AND ledger.economy_generation = :achievement_generation '
            . "AND ledger.event_type = 'achievement_reward' AND ledger.coin_status = 'eligible') "
            . 'AS achievement_coins, '
            . '(SELECT COALESCE(SUM(allocation.amount), 0) FROM coin_spend_allocations allocation '
            . 'INNER JOIN coin_ledger spend_ledger ON spend_ledger.event_id = allocation.spend_event_id '
            . 'WHERE allocation.player_id = :spend_player_id '
            . "AND allocation.source = 'earned' AND allocation.released_at IS NULL "
            . 'AND spend_ledger.economy_generation = :spend_generation) AS earned_spend, '
            . '(SELECT COALESCE(SUM(allocation.amount - allocation.released_amount), 0) '
            . 'FROM storekit_refund_debt_allocations allocation '
            . 'WHERE allocation.player_id = :refund_player_id '
            . "AND allocation.source_type = 'earned_credit' "
            . 'AND allocation.source_economy_generation = :refund_generation '
            . 'AND allocation.source_revoked_at IS NULL '
            . 'AND allocation.released_amount < allocation.amount) AS earned_refund_settlement'
        );
        $economyStatement->execute([
            'achievement_player_id' => $playerId,
            'achievement_generation' => $economyGeneration,
            'spend_player_id' => $playerId,
            'spend_generation' => $economyGeneration,
            'refund_player_id' => $playerId,
            'refund_generation' => $economyGeneration,
        ]);
        $economy = $economyStatement->fetch() ?: [];
        $achievementCoins = (int) ($economy['achievement_coins'] ?? 0);
        $earnedSpend = (int) ($economy['earned_spend'] ?? 0);
        $earnedRefundSettlement = (int) ($economy['earned_refund_settlement'] ?? 0);
        $netCoins = intdiv($totalPlayMs, 60_000) + $achievementCoins
            - $earnedSpend - $earnedRefundSettlement;
        $earnedCreditIncrease = max(
            0,
            $netCoins - (
                (int) $player['earned_coins'] - (int) $player['earned_coin_debt']
            ),
        );
        $newRefundSettlement = $this->settleNewModeratedEarnedCredit(
            $playerId,
            $runId,
            $economyGeneration,
            $earnedCreditIncrease,
            (int) $player['refund_coin_debt'],
        );
        $netCoins -= $newRefundSettlement;
        $player['refund_coin_debt'] = (int) $player['refund_coin_debt'] - $newRefundSettlement;
        $walletBuckets = CoinEconomy::fromEarnedNet(
            $netCoins,
            (int) $player['purchased_coins'],
            (int) $player['refund_coin_debt'],
        );
        $wallet = CoinEconomy::summary(
            $walletBuckets['earnedCoins'],
            $walletBuckets['purchasedCoins'],
            $walletBuckets['earnedDebt'],
            $walletBuckets['refundDebt'],
        );
        $coinBalance = $wallet['coins'];
        $coinDebt = $wallet['debt'];
        $remainderMs = $totalPlayMs % 60_000;
        $totalCoinsCollected = intdiv($verifiedPlayMs, 60_000) + $achievementCoins;
        $oldCoinBalance = (int) $player['coins'];
        $oldCoinDebt = (int) $player['coin_debt'];
        $oldRemainderMs = (int) $player['coin_time_remainder_ms'];
        $oldTotalPlayMs = (int) $player['total_play_ms'];

        $update = $this->database->prepare(
            'UPDATE players SET earned_coins = :earned_coins, purchased_coins = :purchased_coins, '
            . 'earned_coin_debt = :earned_coin_debt, refund_coin_debt = :refund_coin_debt, '
            . 'coins = :coins, coin_debt = :coin_debt, '
            . 'total_coins_collected = :total_coins_collected, coin_time_remainder_ms = :remainder_ms, '
            . 'total_play_ms = :total_play_ms, updated_at = UTC_TIMESTAMP(3) WHERE id = :player_id'
        );
        $update->execute([
            'coins' => $coinBalance,
            'coin_debt' => $coinDebt,
            'earned_coins' => $wallet['earnedCoins'],
            'purchased_coins' => $wallet['purchasedCoins'],
            'earned_coin_debt' => $wallet['earnedDebt'],
            'refund_coin_debt' => $wallet['refundDebt'],
            'total_coins_collected' => $totalCoinsCollected,
            'remainder_ms' => $remainderMs,
            'total_play_ms' => $totalPlayMs,
            'player_id' => $playerId,
        ]);

        $eventKeyPrefix = $eventType === 'manual_reconcile' ? 'reconcile:' : 'moderation:';
        $ledger = $this->database->prepare(
            'INSERT INTO coin_ledger '
            . '(event_id, event_key, player_id, economy_generation, run_id, event_type, play_ms_delta, coin_delta, '
            . 'remainder_before_ms, remainder_after_ms, earned_delta, purchased_delta, '
            . 'coin_balance_after, earned_balance_after, purchased_balance_after, coin_debt_after, '
            . 'earned_debt_after, refund_debt_after, total_play_ms_after, '
            . 'coin_status, actor, reason) VALUES '
            . '(:event_id, :event_key, :player_id, :economy_generation, :run_id, :event_type, :play_ms_delta, '
            . ':coin_delta, :remainder_before_ms, :remainder_after_ms, :earned_delta, 0, '
            . ':coin_balance_after, :earned_balance_after, :purchased_balance_after, '
            . ':coin_debt_after, :earned_debt_after, :refund_debt_after, '
            . ':total_play_ms_after, :coin_status, :actor, :reason)'
        );
        $ledger->execute([
            'event_id' => Uuid::v4(),
            'event_key' => $eventKeyPrefix . $eventId,
            'player_id' => $playerId,
            'economy_generation' => $economyGeneration,
            'run_id' => $runId,
            'event_type' => $eventType,
            'play_ms_delta' => $totalPlayMs - $oldTotalPlayMs,
            'coin_delta' => $wallet['net'] - $oldWallet['net'],
            'earned_delta' => ($wallet['earnedCoins'] - $wallet['earnedDebt'])
                - ($oldWallet['earnedCoins'] - $oldWallet['earnedDebt']),
            'remainder_before_ms' => $oldRemainderMs,
            'remainder_after_ms' => $remainderMs,
            'coin_balance_after' => $coinBalance,
            'earned_balance_after' => $wallet['earnedCoins'],
            'purchased_balance_after' => $wallet['purchasedCoins'],
            'coin_debt_after' => $coinDebt,
            'earned_debt_after' => $wallet['earnedDebt'],
            'refund_debt_after' => $wallet['refundDebt'],
            'total_play_ms_after' => $totalPlayMs,
            'coin_status' => $coinStatus,
            'actor' => $actor,
            'reason' => $reason,
        ]);

        return [
            'oldCoinBalance' => $oldCoinBalance,
            'coinBalance' => $coinBalance,
            'oldCoinDebt' => $oldCoinDebt,
            'coinDebt' => $coinDebt,
            'coinDelta' => $wallet['net'] - $oldWallet['net'],
            'oldRemainderMs' => $oldRemainderMs,
            'remainderMs' => $remainderMs,
            'oldTotalPlayMs' => $oldTotalPlayMs,
            'totalPlayMs' => $totalPlayMs,
            'playMsDelta' => $totalPlayMs - $oldTotalPlayMs,
            'totalCoinsCollected' => $totalCoinsCollected,
        ];
    }

    /**
     * Keep earned credits that settled StoreKit refund debt synchronized with
     * moderation. Revocation reopens the exact target debt; reinstatement
     * consumes the restored credit against that same target before it can
     * become spendable.
     */
    private function syncEarnedRefundDebtSettlements(
        string $playerId,
        string $runId,
        int $economyGeneration,
        string $coinStatus,
    ): int {
        $runReference = 'run:' . $runId;
        $statement = $this->database->prepare(
            'SELECT allocation_id, source_reference, source_economy_generation, '
            . 'refund_transaction_id, cosmetic_restore_debt_id, amount, released_amount, '
            . 'source_revoked_at FROM storekit_refund_debt_allocations '
            . 'WHERE player_id = :player_id AND source_type = \'earned_credit\' '
            . 'AND released_amount < amount '
            . 'AND (source_economy_generation <> :economy_generation '
            . 'OR source_reference = :run_reference) FOR UPDATE'
        );
        $statement->execute([
            'player_id' => $playerId,
            'economy_generation' => $economyGeneration,
            'run_reference' => $runReference,
        ]);
        $debtDelta = 0;
        foreach ($statement->fetchAll() as $allocation) {
            $sameGeneration = (int) $allocation['source_economy_generation'] === $economyGeneration;
            $isCurrentRun = hash_equals((string) $allocation['source_reference'], $runReference);
            $shouldBeActive = $sameGeneration && (!$isCurrentRun || $coinStatus === 'eligible');
            $isActive = $allocation['source_revoked_at'] === null;
            if ($shouldBeActive === $isActive) continue;

            $amount = (int) $allocation['amount'] - (int) $allocation['released_amount'];
            $componentId = $allocation['cosmetic_restore_debt_id'];
            if ($shouldBeActive) {
                $settledAmount = min(
                    $amount,
                    $this->moderatedRefundComponentOutstanding(
                        (string) $allocation['refund_transaction_id'],
                        is_string($componentId) ? $componentId : null,
                    ),
                );
                if ($settledAmount > 0) {
                    $this->changeModeratedRefundComponent(
                        (string) $allocation['refund_transaction_id'],
                        is_string($componentId) ? $componentId : null,
                        -$settledAmount,
                    );
                }
                $releasedAmount = $amount - $settledAmount;
                $restore = $this->database->prepare(
                    'UPDATE storekit_refund_debt_allocations SET source_revoked_at = NULL, '
                    . 'released_amount = released_amount + :released_increment, '
                    . 'released_at = CASE WHEN :release_completed = 1 '
                    . 'THEN UTC_TIMESTAMP(3) ELSE NULL END WHERE allocation_id = :allocation_id '
                    . 'AND source_revoked_at IS NOT NULL '
                    . 'AND amount - released_amount >= :active_minimum'
                );
                $restore->bindValue(':released_increment', $releasedAmount, PDO::PARAM_INT);
                $restore->bindValue(':release_completed', $settledAmount === 0 ? 1 : 0, PDO::PARAM_INT);
                $restore->bindValue(':active_minimum', $amount, PDO::PARAM_INT);
                $restore->bindValue(':allocation_id', $allocation['allocation_id']);
                $restore->execute();
                if ($restore->rowCount() !== 1) {
                    throw new RuntimeException('Moderated refund settlement changed during restoration.');
                }
                $debtDelta -= $settledAmount;
            } else {
                $this->changeModeratedRefundComponent(
                    (string) $allocation['refund_transaction_id'],
                    is_string($componentId) ? $componentId : null,
                    $amount,
                );
                $this->database->prepare(
                    'UPDATE storekit_refund_debt_allocations SET source_revoked_at = UTC_TIMESTAMP(3) '
                    . 'WHERE allocation_id = :allocation_id AND source_revoked_at IS NULL'
                )->execute(['allocation_id' => $allocation['allocation_id']]);
                $debtDelta += $amount;
            }
        }
        return $debtDelta;
    }

    /**
     * A moderation decision can turn previously withheld play into a new earned
     * credit. Preserve the ordinary credit ordering by consuming outstanding
     * StoreKit refund debt before any of that increase becomes spendable.
     */
    private function settleNewModeratedEarnedCredit(
        string $playerId,
        string $runId,
        int $economyGeneration,
        int $earnedCreditIncrease,
        int $refundDebt,
    ): int {
        $settlement = min($earnedCreditIncrease, $refundDebt);
        if ($settlement === 0) return 0;

        (new CoinWalletRepository($this->database))->allocateRefundDebtPayment(
            $playerId,
            'earned_credit',
            'run:' . $runId,
            null,
            $settlement,
            $economyGeneration,
        );
        return $settlement;
    }

    /** Return the still-unpaid amount of one exact StoreKit refund component. */
    private function moderatedRefundComponentOutstanding(
        string $transactionId,
        ?string $cosmeticDebtId,
    ): int {
        if ($cosmeticDebtId === null) {
            $statement = $this->database->prepare(
                'SELECT refund_debt_outstanding, base_refund_debt_outstanding '
                . 'FROM storekit_transactions WHERE transaction_id = :transaction_id FOR UPDATE'
            );
            $statement->execute(['transaction_id' => $transactionId]);
            $transaction = $statement->fetch();
            if (!is_array($transaction)) {
                throw new RuntimeException('Moderated refund debt transaction was not found.');
            }
            $outstanding = (int) $transaction['base_refund_debt_outstanding'];
            if ($outstanding > (int) $transaction['refund_debt_outstanding']) {
                throw new RuntimeException('Moderated base refund debt drifted from its aggregate.');
            }
            return $outstanding;
        }

        $statement = $this->database->prepare(
            'SELECT debt.amount, debt.settled_amount, debt.released_at, '
            . 'stored.refund_debt_outstanding FROM storekit_cosmetic_restore_debts debt '
            . 'INNER JOIN storekit_transactions stored '
            . 'ON stored.transaction_id = debt.refund_transaction_id '
            . 'WHERE debt.debt_id = :debt_id AND debt.refund_transaction_id = :transaction_id FOR UPDATE'
        );
        $statement->execute([
            'debt_id' => $cosmeticDebtId,
            'transaction_id' => $transactionId,
        ]);
        $component = $statement->fetch();
        if (!is_array($component)) {
            throw new RuntimeException('Moderated cosmetic refund debt was not found.');
        }
        if ($component['released_at'] !== null) return 0;

        $outstanding = (int) $component['amount'] - (int) $component['settled_amount'];
        if ($outstanding < 0 || $outstanding > (int) $component['refund_debt_outstanding']) {
            throw new RuntimeException('Moderated cosmetic refund debt drifted from its aggregate.');
        }
        return $outstanding;
    }

    /** Positive amount reopens debt; negative amount settles it again. */
    private function changeModeratedRefundComponent(
        string $transactionId,
        ?string $cosmeticDebtId,
        int $amount,
    ): void {
        if ($amount === 0) return;
        if ($cosmeticDebtId === null) {
            $statement = $this->database->prepare(
                'UPDATE storekit_transactions SET refund_debt_outstanding = '
                . 'refund_debt_outstanding + :total_delta, base_refund_debt_outstanding = '
                . 'base_refund_debt_outstanding + :base_delta WHERE transaction_id = :transaction_id '
                . 'AND refund_debt_outstanding + :total_guard >= 0 '
                . 'AND base_refund_debt_outstanding + :base_guard >= 0'
            );
            $statement->bindValue(':total_delta', $amount, PDO::PARAM_INT);
            $statement->bindValue(':base_delta', $amount, PDO::PARAM_INT);
            $statement->bindValue(':total_guard', $amount, PDO::PARAM_INT);
            $statement->bindValue(':base_guard', $amount, PDO::PARAM_INT);
            $statement->bindValue(':transaction_id', $transactionId);
            $statement->execute();
        } else {
            $debt = $this->database->prepare(
                'UPDATE storekit_cosmetic_restore_debts SET settled_amount = settled_amount - :delta '
                . 'WHERE debt_id = :debt_id AND settled_amount - :guard BETWEEN 0 AND amount'
            );
            $debt->bindValue(':delta', $amount, PDO::PARAM_INT);
            $debt->bindValue(':guard', $amount, PDO::PARAM_INT);
            $debt->bindValue(':debt_id', $cosmeticDebtId);
            $debt->execute();
            if ($debt->rowCount() !== 1) {
                throw new RuntimeException('Moderated cosmetic refund debt drifted.');
            }
            $statement = $this->database->prepare(
                'UPDATE storekit_transactions SET refund_debt_outstanding = '
                . 'refund_debt_outstanding + :delta WHERE transaction_id = :transaction_id '
                . 'AND refund_debt_outstanding + :guard >= 0'
            );
            $statement->bindValue(':delta', $amount, PDO::PARAM_INT);
            $statement->bindValue(':guard', $amount, PDO::PARAM_INT);
            $statement->bindValue(':transaction_id', $transactionId);
            $statement->execute();
        }
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Moderated refund debt transaction drifted.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{code: string, severity: string, detail: string}>
     */
    private function auditFlags(array $row): array
    {
        $flags = [];
        $add = static function (string $code, string $severity, string $detail) use (&$flags): void {
            $flags[] = ['code' => $code, 'severity' => $severity, 'detail' => $detail];
        };
        $hits = (int) ($row['correct_taps'] ?? 0);
        $dodges = (int) ($row['dodge_count'] ?? 0);
        $score = (int) ($row['score'] ?? 0);
        $duration = (int) ($row['duration_ms'] ?? 0);
        $dodgePoints = ($row['mode'] ?? null) === 'zen' ? 0 : self::DODGE_POINTS;
        $ratingTotal = (int) ($row['godlike_count'] ?? 0)
            + (int) ($row['perfect_count'] ?? 0)
            + (int) ($row['great_count'] ?? 0)
            + (int) ($row['good_count'] ?? 0);
        $minimumScore = $hits * self::MIN_POINTS_PER_HIT + $dodges * $dodgePoints;
        $maximumScore = $hits * self::MAX_POINTS_PER_HIT + $dodges * $dodgePoints;
        if ($score < $minimumScore || $score > $maximumScore) {
            $add(
                'score-outside-aggregate-bounds',
                'high',
                'Score is outside the possible per-hit range plus mode-specific dodge awards.',
            );
        }
        if ($ratingTotal !== $hits) {
            $add('rating-count-mismatch', 'high', 'Speed rating counts do not equal correct taps.');
        }
        if (($row['mode'] ?? null) === 'zen' && $duration !== 180_000) {
            $add('zen-duration-invalid', 'high', 'Zen duration is not exactly 180000 ms.');
        }
        if (($row['mode'] ?? null) === 'normal' && $duration > 21_600_000) {
            $add('extreme-normal-duration', 'medium', 'Normal duration exceeds six hours.');
        }
        $maximumDodges = $duration <= self::FIRST_DECOY_AT_MS
            ? 0
            : intdiv($duration - self::FIRST_DECOY_AT_MS, self::MIN_DECOY_GAP_MS) + 1;
        if ($dodges > $maximumDodges) {
            $add('impossible-dodge-cadence', 'high', 'Dodge count exceeds the minimum decoy opportunity gap.');
        }
        $fastest = $row['fastest_reaction_ms'] ?? null;
        $average = $row['average_reaction_ms'] ?? null;
        if ($hits === 0 && ($fastest !== null || $average !== null)) {
            $add('reaction-stats-without-hits', 'high', 'Reaction statistics exist for a zero-hit run.');
        }
        if ($hits > 0 && ($fastest === null || $average === null || (int) $fastest > (int) $average)) {
            $add('reaction-stats-invalid', 'high', 'Reaction statistics are missing or internally inconsistent.');
        }
        if ($hits >= 10 && $fastest !== null && (int) $fastest < 30) {
            $add('sub-30ms-reaction', 'medium', 'A sustained run contains a reaction below 30 ms.');
        }
        if ($hits >= 20 && $average !== null && (int) $average < 80) {
            $add('sub-80ms-average', 'medium', 'Average reaction below 80 ms warrants automation review.');
        }
        if ($hits >= 50 && (int) ($row['godlike_count'] ?? 0) * 100 >= $hits * 98) {
            $add('near-perfect-godlike-ratio', 'medium', 'At least 98% of 50+ taps are Godlike.');
        }

        if (!isset($row['run_id']) || $row['run_id'] === null) {
            $add('no-strict-completed-run-link', 'info', 'No completed run matched exact ID, player, mode, score, and duration.');
        } else {
            if (
                !hash_equals((string) ($row['player_id'] ?? ''), (string) ($row['run_player_id'] ?? ''))
                || (string) ($row['mode'] ?? '') !== (string) ($row['run_mode'] ?? '')
                || $score !== (int) ($row['run_score'] ?? -1)
                || $duration !== (int) ($row['run_duration_ms'] ?? -1)
            ) {
                $add('linked-run-mismatch', 'high', 'Linked completed run does not match the leaderboard result.');
            }
            $expected = (int) ($row['reaction_base_points'] ?? 0)
                + (int) ($row['multiplier_bonus_points'] ?? 0)
                + $dodges * $dodgePoints;
            if ($expected !== $score) {
                $add('completed-run-score-mismatch', 'high', 'Completed-run point components do not equal the score.');
            }
            $maximumCoinsForRun = intdiv($duration + 59_999, 60_000);
            if ((int) ($row['coins_awarded'] ?? 0) > $maximumCoinsForRun) {
                $add('impossible-run-coin-award', 'high', 'Run awarded more coins than its duration plus carry can produce.');
            }
            $runStatus = (string) ($row['run_status'] ?? '');
            $entryStatus = (string) ($row['verification_status'] ?? '');
            if ($runStatus !== '' && $runStatus !== $entryStatus) {
                $add('verification-status-mismatch', 'medium', 'Leaderboard and completed-run moderation states differ.');
            }
        }

        if ((int) ($row['risk_score'] ?? 0) > 0) {
            $add('recorded-risk-score', 'info', 'The validator recorded a non-zero risk score.');
        }
        return $flags;
    }

    /** @param list<array{code: string, severity: string, detail: string}> $flags */
    private function highestSeverity(array $flags): string
    {
        $rank = ['info' => 0, 'medium' => 1, 'high' => 2];
        $highest = 'info';
        foreach ($flags as $flag) {
            if (($rank[$flag['severity']] ?? 0) > $rank[$highest]) {
                $highest = $flag['severity'];
            }
        }
        return $highest;
    }

    /** @param array<string, mixed> $row */
    private function normalizeListRow(array $row): array
    {
        $row = $this->normalizeNumericFields($row);
        if (is_string($row['player_id'] ?? null)) {
            $row['playerId'] = $row['player_id'];
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function normalizeNumericFields(array $row): array
    {
        foreach ([
            'score', 'duration_ms', 'correct_taps', 'dodge_count', 'fastest_reaction_ms',
            'average_reaction_ms', 'godlike_count', 'perfect_count', 'great_count',
            'good_count', 'proof_version', 'risk_score', 'coins', 'coins_awarded',
            'coin_time_remainder_ms', 'total_play_ms', 'server_elapsed_ms', 'miss_count',
        ] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function normalizeBinaryHashes(array $row): array
    {
        foreach (['payload_hash', 'trace_hash', 'session_binding_hash', 'proof_hash'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $row[$field . '_hex'] = bin2hex($row[$field]);
                unset($row[$field]);
            }
        }
        return $this->normalizeNumericFields($row);
    }

    /** @param array<string, mixed> $row */
    private function decodeJsonFields(array $row): array
    {
        if (isset($row['details_json']) && is_string($row['details_json'])) {
            $row['details'] = json_decode($row['details_json'], true);
            unset($row['details_json']);
        }
        return $row;
    }
}
