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
    ): array {
        $parameters = [];
        $where = $this->filterWhere($seasonId, $mode, $status, $parameters);
        $limit = $this->validateLimit($limit);
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
            . ' ORDER BY l.achieved_at DESC, l.id DESC LIMIT ' . $limit
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
    ): array {
        $parameters = [];
        $where = $this->filterWhere($seasonId, $mode, $status, $parameters);
        $limit = $this->validateLimit($limit);
        $statement = $this->database->prepare(
            'SELECT l.*, p.nickname, c.run_id, c.mode AS run_mode, c.score AS run_score, '
            . 'c.duration_ms AS run_duration_ms, c.reaction_base_points, '
            . 'c.multiplier_bonus_points, c.coins_awarded, c.verification_status AS run_status, '
            . 'c.coin_status, c.player_id AS run_player_id '
            . 'FROM leaderboard_entries l '
            . 'INNER JOIN players p ON p.id = l.player_id '
            . 'LEFT JOIN completed_runs c ON c.leaderboard_entry_id = l.id '
            . $where
            . ' ORDER BY l.score DESC, l.achieved_at ASC, l.id ASC LIMIT ' . $limit
        );
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
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
        ];
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
            throw new RuntimeException('Leaderboard entry was not found.');
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
    ): array {
        $entryId = $this->validateEntryId($entryId);
        if (!in_array($action, self::MODERATION_ACTIONS, true)) {
            throw new InvalidArgumentException('Moderation action is invalid.');
        }
        [$actor, $reason] = $this->validateAuditText($actor, $reason);

        $this->database->beginTransaction();
        try {
            $this->lockEntryPlayer($entryId);
            $entry = $this->lockEntry($entryId);
            $fromStatus = (string) $entry['verification_status'];
            $toStatus = match ($action) {
                'approve' => $this->reviewStatus($fromStatus, 'verified'),
                'reject' => $this->reviewStatus($fromStatus, 'quarantined'),
                'quarantine' => 'quarantined',
                'delete' => $this->deleteStatus($fromStatus),
                'restore' => $this->restoreStatus($entryId, $fromStatus),
            };
            if ($action === 'quarantine' && $fromStatus === 'deleted') {
                throw new RuntimeException('A deleted result must be restored before it can be quarantined.');
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
        if ($status !== null) {
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
            throw new RuntimeException('Leaderboard entry was not found.');
        }
        return $entry;
    }

    private function lockEntryPlayer(string $entryId): void
    {
        // Discover the immutable owner, then take the same player-first lock
        // order used by score submission before locking the entry itself.
        $owner = $this->database->prepare(
            'SELECT player_id FROM leaderboard_entries WHERE id = :entry_id LIMIT 1'
        );
        $owner->execute(['entry_id' => $entryId]);
        $playerId = $owner->fetchColumn();
        if (!is_string($playerId)) {
            throw new RuntimeException('Leaderboard entry was not found.');
        }
        $player = $this->database->prepare(
            'SELECT id FROM players WHERE id = :player_id FOR UPDATE'
        );
        $player->execute(['player_id' => $playerId]);
        if ($player->fetchColumn() === false) {
            throw new RuntimeException('Player was not found for moderation.');
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

    private function restoreStatus(string $entryId, string $currentStatus): string
    {
        if ($currentStatus !== 'quarantined' && $currentStatus !== 'deleted') {
            throw new RuntimeException('Only quarantined or logically deleted results can be restored.');
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
            throw new RuntimeException('No audited pre-moderation state is available for restore.');
        }
        return $status;
    }

    private function reviewStatus(string $currentStatus, string $targetStatus): string
    {
        if ($currentStatus !== 'review') {
            throw new RuntimeException('Only a result held for review can be approved or rejected.');
        }
        return $targetStatus;
    }

    private function deleteStatus(string $currentStatus): string
    {
        if ($currentStatus !== 'quarantined') {
            throw new RuntimeException('A result must be quarantined before logical deletion.');
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
            'SELECT coins, coin_debt, total_coins_collected, coin_time_remainder_ms, total_play_ms FROM players '
            . 'WHERE id = :player_id FOR UPDATE'
        );
        $playerStatement->execute(['player_id' => $playerId]);
        $player = $playerStatement->fetch();
        if (!is_array($player)) {
            throw new RuntimeException('Player was not found for coin reconciliation.');
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
            . "WHERE player_id = :player_id AND coin_status IN ('legacy','eligible')"
        );
        $eligible->execute(['player_id' => $playerId]);
        $playTime = $eligible->fetch() ?: [];
        $totalPlayMs = (int) ($playTime['eligible_play_ms'] ?? 0);
        $verifiedPlayMs = (int) ($playTime['verified_play_ms'] ?? 0);

        $economyStatement = $this->database->prepare(
            "SELECT COALESCE(SUM(coin_delta), 0) AS economy_delta, "
            . "COALESCE(SUM(CASE WHEN event_type = 'achievement_reward' THEN coin_delta ELSE 0 END), 0) "
            . 'AS achievement_coins FROM coin_ledger '
            . "WHERE player_id = :player_id AND event_type IN ('pet_purchase','achievement_reward')"
        );
        $economyStatement->execute(['player_id' => $playerId]);
        $economy = $economyStatement->fetch() ?: [];
        $economyDelta = (int) ($economy['economy_delta'] ?? 0);
        $achievementCoins = (int) ($economy['achievement_coins'] ?? 0);
        $netCoins = intdiv($totalPlayMs, 60_000) + $economyDelta;
        $wallet = CoinEconomy::fromNet($netCoins);
        $coinBalance = $wallet['coins'];
        $coinDebt = $wallet['debt'];
        $remainderMs = $totalPlayMs % 60_000;
        $totalCoinsCollected = intdiv($verifiedPlayMs, 60_000) + $achievementCoins;
        $oldCoinBalance = (int) $player['coins'];
        $oldCoinDebt = (int) $player['coin_debt'];
        $oldRemainderMs = (int) $player['coin_time_remainder_ms'];
        $oldTotalPlayMs = (int) $player['total_play_ms'];

        $update = $this->database->prepare(
            'UPDATE players SET coins = :coins, coin_debt = :coin_debt, '
            . 'total_coins_collected = :total_coins_collected, coin_time_remainder_ms = :remainder_ms, '
            . 'total_play_ms = :total_play_ms, updated_at = UTC_TIMESTAMP(3) WHERE id = :player_id'
        );
        $update->execute([
            'coins' => $coinBalance,
            'coin_debt' => $coinDebt,
            'total_coins_collected' => $totalCoinsCollected,
            'remainder_ms' => $remainderMs,
            'total_play_ms' => $totalPlayMs,
            'player_id' => $playerId,
        ]);

        $eventKeyPrefix = $eventType === 'manual_reconcile' ? 'reconcile:' : 'moderation:';
        $ledger = $this->database->prepare(
            'INSERT INTO coin_ledger '
            . '(event_id, event_key, player_id, run_id, event_type, play_ms_delta, coin_delta, '
            . 'remainder_before_ms, remainder_after_ms, coin_balance_after, coin_debt_after, '
            . 'total_play_ms_after, '
            . 'coin_status, actor, reason) VALUES '
            . '(:event_id, :event_key, :player_id, :run_id, :event_type, :play_ms_delta, '
            . ':coin_delta, :remainder_before_ms, :remainder_after_ms, :coin_balance_after, '
            . ':coin_debt_after, :total_play_ms_after, :coin_status, :actor, :reason)'
        );
        $ledger->execute([
            'event_id' => Uuid::v4(),
            'event_key' => $eventKeyPrefix . $eventId,
            'player_id' => $playerId,
            'run_id' => $runId,
            'event_type' => $eventType,
            'play_ms_delta' => $totalPlayMs - $oldTotalPlayMs,
            'coin_delta' => CoinEconomy::net($coinBalance, $coinDebt)
                - CoinEconomy::net($oldCoinBalance, $oldCoinDebt),
            'remainder_before_ms' => $oldRemainderMs,
            'remainder_after_ms' => $remainderMs,
            'coin_balance_after' => $coinBalance,
            'coin_debt_after' => $coinDebt,
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
            'coinDelta' => CoinEconomy::net($coinBalance, $coinDebt)
                - CoinEconomy::net($oldCoinBalance, $oldCoinDebt),
            'oldRemainderMs' => $oldRemainderMs,
            'remainderMs' => $remainderMs,
            'oldTotalPlayMs' => $oldTotalPlayMs,
            'totalPlayMs' => $totalPlayMs,
            'playMsDelta' => $totalPlayMs - $oldTotalPlayMs,
            'totalCoinsCollected' => $totalCoinsCollected,
        ];
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
        return $this->normalizeNumericFields($row);
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
