<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use Throwable;

/**
 * Owns the encrypted client-asserted gamePlayerID association and the
 * coalescing desired-state outbox. Apple does not sign this identifier in the
 * ordinary non-Apple-Arcade identity tuple; callers must first verify the
 * signed teamPlayerID and a fresh authenticated-session link challenge.
 */
final class GameCenterPublicationRepository
{
    private const ASSIGNMENT_ATTEMPTS = 4;

    private string $encryptionKey;

    public function __construct(
        private readonly PDO $database,
        string $encryptionSecret,
        private readonly bool $preReleased,
    ) {
        if (strlen($encryptionSecret) < 32) {
            throw new \InvalidArgumentException(
                'Game Center player-ID encryption secret must contain at least 32 bytes.',
            );
        }
        $this->encryptionKey = hash_hkdf(
            'sha256',
            $encryptionSecret,
            32,
            'pimpopom-game-center-scoped-player-v1',
        );
    }

    /**
     * Atomically makes the submitted Game Center pair publish the current
     * PimPoPom profile. Game Center is secondary identity only: this operation
     * never moves or merges any profile-owned data.
     *
     * @return array{
     *   enabled: bool,
     *   linked: bool,
     *   newlyBound: bool,
     *   reassigned: bool
     * }
     */
    public function assignCurrentProfile(
        string $playerId,
        string $teamPlayerIdHash,
        string $gamePlayerId,
        string $assertionHash,
        string $assertionExpiresAt,
    ): array {
        if ($this->database->inTransaction()) {
            throw new \LogicException('Game Center reassignment must own its transaction.');
        }
        $playerId = self::normalizedPlayerId($playerId);
        if (strlen($teamPlayerIdHash) !== 32 || strlen($assertionHash) !== 32) {
            throw new \InvalidArgumentException('Game Center identity digest is invalid.');
        }
        $gamePlayerId = self::normalizedGamePlayerId($gamePlayerId);
        $gamePlayerIdHash = hash(
            'sha256',
            "game_center_game_player\0" . $gamePlayerId,
            true,
        );
        [$ciphertext, $iv, $tag] = $this->encrypt(
            $playerId,
            $teamPlayerIdHash,
            $gamePlayerId,
        );

        for ($attempt = 1; $attempt <= self::ASSIGNMENT_ATTEMPTS; $attempt++) {
            $preliminaryPlayers = $this->affectedPlayerIds(
                $playerId,
                $teamPlayerIdHash,
                $gamePlayerIdHash,
                false,
            );
            try {
                return $this->withPlayerPublicationLocks(
                    $preliminaryPlayers,
                    function () use (
                        $playerId,
                        $teamPlayerIdHash,
                        $gamePlayerIdHash,
                        $ciphertext,
                        $iv,
                        $tag,
                        $assertionHash,
                        $assertionExpiresAt,
                        $preliminaryPlayers,
                    ): array {
                        return $this->assignWhileLocked(
                            $playerId,
                            $teamPlayerIdHash,
                            $gamePlayerIdHash,
                            $ciphertext,
                            $iv,
                            $tag,
                            $assertionHash,
                            $assertionExpiresAt,
                            $preliminaryPlayers,
                        );
                    },
                );
            } catch (GameCenterAssignmentRetry $error) {
                if ($attempt === self::ASSIGNMENT_ATTEMPTS) {
                    break;
                }
            } catch (\PDOException $error) {
                $this->rollBack();
                if ($error->getCode() !== '23000') {
                    throw $error;
                }
                if ($attempt === self::ASSIGNMENT_ATTEMPTS) {
                    break;
                }
            }
        }
        throw new ApiException(
            503,
            'Game Center ownership changed while linking. Please try again.',
        );
    }

    /**
     * @param list<string> $lockedPlayerIds
     * @return array{
     *   enabled: bool,
     *   linked: bool,
     *   newlyBound: bool,
     *   reassigned: bool
     * }
     */
    private function assignWhileLocked(
        string $playerId,
        string $teamPlayerIdHash,
        string $gamePlayerIdHash,
        string $ciphertext,
        string $iv,
        string $tag,
        string $assertionHash,
        string $assertionExpiresAt,
        array $lockedPlayerIds,
    ): array {
        $this->database->beginTransaction();
        try {
            $this->lockPlayers($lockedPlayerIds, $playerId);
            $actualPlayerIds = $this->affectedPlayerIds(
                $playerId,
                $teamPlayerIdHash,
                $gamePlayerIdHash,
                true,
            );
            if (array_diff($actualPlayerIds, $lockedPlayerIds) !== []) {
                throw new GameCenterAssignmentRetry(
                    'Game Center binding ownership changed during lock acquisition.',
                );
            }
            $bindings = $this->affectedBindings(
                $playerId,
                $teamPlayerIdHash,
                $gamePlayerIdHash,
                true,
            );
            $current = null;
            foreach ($bindings as $binding) {
                if (hash_equals($playerId, (string) $binding['player_id'])) {
                    $current = $binding;
                    break;
                }
            }

            $this->consumeAssertion($assertionHash, $assertionExpiresAt);
            $timestamp = self::timestamp();
            $currentTeamHash = is_string($current['team_player_id_hash'] ?? null)
                ? $current['team_player_id_hash']
                : null;
            $currentGameHash = is_string($current['game_player_id_hash'] ?? null)
                ? $current['game_player_id_hash']
                : null;
            $sameTeam = is_string($currentTeamHash)
                && hash_equals($currentTeamHash, $teamPlayerIdHash);
            $sameGame = is_string($currentGameHash)
                && hash_equals($currentGameHash, $gamePlayerIdHash);
            $hasCompleteCiphertext = is_string($current['game_player_id_ciphertext'] ?? null)
                && is_string($current['game_player_id_iv'] ?? null)
                && is_string($current['game_player_id_tag'] ?? null);

            if ($sameTeam && $sameGame && $hasCompleteCiphertext) {
                $touch = $this->database->prepare(
                    'UPDATE player_game_center_bindings SET '
                    . 'last_verified_at = :last_verified_at, '
                    . 'publication_enabled_at = COALESCE(publication_enabled_at, :enabled_at), '
                    . 'publication_disabled_at = NULL WHERE player_id = :player_id'
                );
                $touch->execute([
                    'last_verified_at' => $timestamp,
                    'enabled_at' => $timestamp,
                    'player_id' => $playerId,
                ]);
                $this->backfillInCurrentTransaction($playerId);
                $this->database->commit();
                return [
                    'enabled' => true,
                    'linked' => false,
                    'newlyBound' => false,
                    'reassigned' => false,
                ];
            }

            $reassigned = $current !== null
                && (!$sameTeam || (is_string($currentGameHash) && !$sameGame));
            foreach ($bindings as $binding) {
                if (!hash_equals($playerId, (string) $binding['player_id'])) {
                    $reassigned = true;
                    break;
                }
            }
            $this->revisionCancelOutboxes($actualPlayerIds, $timestamp);
            $this->deleteBindings($actualPlayerIds);

            $insert = $this->database->prepare(
                'INSERT INTO player_game_center_bindings '
                . '(player_id, team_player_id_hash, game_player_id_hash, '
                . 'game_player_id_ciphertext, game_player_id_iv, game_player_id_tag, '
                . 'linked_at, last_verified_at, publication_enabled_at, publication_disabled_at) '
                . 'VALUES (:player_id, :team_player_id_hash, :game_player_id_hash, '
                . ':ciphertext, :iv, :tag, :linked_at, :last_verified_at, :enabled_at, NULL)'
            );
            $insert->bindValue(':player_id', $playerId);
            $insert->bindValue(':team_player_id_hash', $teamPlayerIdHash, PDO::PARAM_LOB);
            $insert->bindValue(':game_player_id_hash', $gamePlayerIdHash, PDO::PARAM_LOB);
            $insert->bindValue(':ciphertext', $ciphertext, PDO::PARAM_LOB);
            $insert->bindValue(':iv', $iv, PDO::PARAM_LOB);
            $insert->bindValue(':tag', $tag, PDO::PARAM_LOB);
            $insert->bindValue(':linked_at', $timestamp);
            $insert->bindValue(':last_verified_at', $timestamp);
            $insert->bindValue(':enabled_at', $timestamp);
            $insert->execute();

            $this->backfillInCurrentTransaction($playerId);
            $this->database->commit();
            return [
                'enabled' => true,
                'linked' => !$sameTeam,
                'newlyBound' => true,
                'reassigned' => $reassigned,
            ];
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /** @return array{disabled: bool} */
    public function disable(string $playerId): array
    {
        $playerId = self::normalizedPlayerId($playerId);
        return $this->withPlayerPublicationLock(
            $playerId,
            fn (): array => $this->disableWhileLocked($playerId),
        );
    }

    /** @return array{disabled: bool} */
    private function disableWhileLocked(string $playerId): array
    {
        $this->database->beginTransaction();
        try {
            $binding = $this->binding($playerId, true);
            if ($binding === null || $binding['publication_disabled_at'] !== null) {
                $this->database->commit();
                return ['disabled' => false];
            }
            $timestamp = self::timestamp();
            $disable = $this->database->prepare(
                'UPDATE player_game_center_bindings SET publication_disabled_at = :disabled_at '
                . 'WHERE player_id = :player_id'
            );
            $disable->execute(['disabled_at' => $timestamp, 'player_id' => $playerId]);
            $cancel = $this->database->prepare(
                "UPDATE game_center_publication_outbox SET state = 'cancelled', "
                . 'desired_revision = desired_revision + 1, lock_token = NULL, locked_at = NULL, '
                . 'last_error_code = :error_code, last_error = :last_error, updated_at = :updated_at '
                . "WHERE player_id = :player_id "
                . "AND state IN ('pending','processing','retry','needs_reset')"
            );
            $cancel->execute([
                'error_code' => 'PUBLICATION_DISABLED',
                'last_error' => 'The player disabled Game Center publication.',
                'updated_at' => $timestamp,
                'player_id' => $playerId,
            ]);
            $this->database->commit();
            return ['disabled' => true];
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /**
     * Serialize a player's Apple request with publication disable and account
     * deletion. Holding this lock across the bounded Apple HTTPS request means
     * those privacy operations cannot return while an already-prepared request
     * is still able to publish.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function withPlayerPublicationLock(string $playerId, callable $operation): mixed
    {
        return $this->withPlayerPublicationLocks([$playerId], $operation);
    }

    /**
     * @template T
     * @param list<string> $playerIds
     * @param callable(): T $operation
     * @return T
     */
    public function withPlayerPublicationLocks(array $playerIds, callable $operation): mixed
    {
        $playerIds = array_values(array_unique(array_map(
            static fn (string $playerId): string => self::normalizedPlayerId($playerId),
            $playerIds,
        )));
        sort($playerIds, SORT_STRING);
        if ($playerIds === []) {
            throw new \InvalidArgumentException('At least one Game Center player lock is required.');
        }
        if ($this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return $operation();
        }
        if ($this->database->inTransaction()) {
            throw new \LogicException('Acquire the Game Center player lock before a transaction.');
        }
        $acquired = [];
        $deadline = microtime(true) + 25;
        try {
            foreach ($playerIds as $playerId) {
                $lockName = self::publicationLockName($playerId);
                $timeout = max(0, (int) ceil($deadline - microtime(true)));
                $lock = $this->database->prepare(
                    'SELECT GET_LOCK(:lock_name, :timeout_seconds)'
                );
                $lock->bindValue(':lock_name', $lockName);
                $lock->bindValue(':timeout_seconds', $timeout, PDO::PARAM_INT);
                $lock->execute();
                if ((int) $lock->fetchColumn() !== 1) {
                    throw new ApiException(
                        503,
                        'Game Center publication is busy. Please try again.',
                    );
                }
                $acquired[] = $lockName;
            }
            return $operation();
        } finally {
            $acquired = array_reverse($acquired);
            foreach ($acquired as $lockName) {
                $release = $this->database->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute(['lock_name' => $lockName]);
            }
        }
    }

    /**
     * @return array{
     *   identityLinked: bool,
     *   publicationEnabled: bool,
     *   mirrorReady: bool,
     *   preReleased: bool,
     *   pendingJobs: int,
     *   heldJobs: int,
     *   needsReset: bool
     * }
     */
    public function status(string $playerId): array
    {
        $playerId = self::normalizedPlayerId($playerId);
        $binding = $this->binding($playerId, false);
        $identityLinked = $binding !== null;
        $publicationEnabled = $identityLinked
            && $binding['publication_enabled_at'] !== null
            && $binding['publication_disabled_at'] === null
            && is_string($binding['game_player_id_hash'] ?? null);
        $summary = $this->database->prepare(
            'SELECT '
            . "COALESCE(SUM(state IN ('pending','processing','retry')), 0) AS pending_jobs, "
            . "COALESCE(SUM(state = 'held'), 0) AS held_jobs, "
            . "COALESCE(MAX(state = 'needs_reset'), 0) AS needs_reset "
            . 'FROM game_center_publication_outbox '
            . 'WHERE player_id = :player_id AND pre_released = :pre_released'
        );
        $summary->execute([
            'player_id' => $playerId,
            'pre_released' => $this->preReleased ? 1 : 0,
        ]);
        $row = $summary->fetch() ?: [];
        return [
            'identityLinked' => $identityLinked,
            'publicationEnabled' => $publicationEnabled,
            'mirrorReady' => $publicationEnabled,
            'preReleased' => $this->preReleased,
            'pendingJobs' => (int) ($row['pending_jobs'] ?? 0),
            'heldJobs' => (int) ($row['held_jobs'] ?? 0),
            'needsReset' => (bool) ($row['needs_reset'] ?? false),
        ];
    }

    public function enqueueBestScoreInCurrentTransaction(string $playerId): void
    {
        $this->enqueueBestLeaderboardScoreInCurrentTransaction(
            $playerId,
            GameCenterCatalog::LEADERBOARD_ARCADE_VERIFIED,
        );
    }

    public function enqueueBestMultiplayerScoreInCurrentTransaction(
        string $playerId,
    ): void {
        $this->enqueueBestLeaderboardScoreInCurrentTransaction(
            $playerId,
            GameCenterCatalog::LEADERBOARD_MULTIPLAYER_VERIFIED,
        );
    }

    private function enqueueBestLeaderboardScoreInCurrentTransaction(
        string $playerId,
        string $vendorIdentifier,
    ): void
    {
        $this->requireTransaction();
        $playerId = self::normalizedPlayerId($playerId);
        if (!GameCenterCatalog::supportsLeaderboardVendorIdentifier($vendorIdentifier)) {
            throw new \InvalidArgumentException(
                'Unknown Game Center leaderboard identifier.',
            );
        }
        if (!$this->publicationIsActive($playerId, true)) {
            return;
        }
        $best = $this->authoritativeLeaderboardScore(
            $playerId,
            $vendorIdentifier,
        );
        if ($best === null) {
            $this->markLeaderboardWithoutDesiredScore(
                $playerId,
                $vendorIdentifier,
            );
            return;
        }
        $this->queueDesired(
            $playerId,
            'leaderboard',
            $vendorIdentifier,
            $best,
        );
    }

    public function enqueueAchievementInCurrentTransaction(
        string $playerId,
        string $achievementId,
    ): void {
        $this->requireTransaction();
        $playerId = self::normalizedPlayerId($playerId);
        if (!$this->publicationIsActive($playerId, true)) {
            return;
        }
        $this->queueDesired(
            $playerId,
            'achievement',
            GameCenterCatalog::achievementVendorIdentifier($achievementId),
            100,
        );
    }

    public function backfillInCurrentTransaction(string $playerId): void
    {
        $this->requireTransaction();
        $this->enqueueBestScoreInCurrentTransaction($playerId);
        $this->enqueueBestMultiplayerScoreInCurrentTransaction($playerId);
        $statement = $this->database->prepare(
            'SELECT achievement_key FROM player_achievements WHERE player_id = :player_id'
        );
        $statement->execute(['player_id' => $playerId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $achievementId) {
            if (
                is_string($achievementId)
                && GameCenterCatalog::supportsAchievement($achievementId)
            ) {
                $this->enqueueAchievementInCurrentTransaction($playerId, $achievementId);
            }
        }
    }

    /**
     * Explicit release-lane/bootstrap operation. It is intentionally not run
     * on ordinary API reads.
     */
    public function backfillAllActiveBindings(): int
    {
        if ($this->database->inTransaction()) {
            throw new \LogicException('Game Center bulk backfill owns its transactions.');
        }
        $statement = $this->database->query(
            'SELECT player_id FROM player_game_center_bindings '
            . 'WHERE publication_enabled_at IS NOT NULL AND publication_disabled_at IS NULL '
            . 'ORDER BY player_id ASC'
        );
        $playerIds = array_values(array_map(
            'strval',
            $statement->fetchAll(PDO::FETCH_COLUMN),
        ));
        $backfilled = 0;
        foreach ($playerIds as $playerId) {
            $this->database->beginTransaction();
            try {
                if ($this->publicationIsActive($playerId, true)) {
                    $this->backfillInCurrentTransaction($playerId);
                    $backfilled++;
                }
                $this->database->commit();
            } catch (Throwable $error) {
                $this->rollBack();
                throw $error;
            }
        }
        return $backfilled;
    }

    /**
     * @return list<array{
     *   id: string,
     *   publicationKind: string,
     *   vendorIdentifier: string,
     *   attemptCount: int,
     *   httpStatus: ?int,
     *   errorCode: ?string,
     *   diagnostic: ?string,
     *   updatedAt: string
     * }>
     */
    public function heldDiagnostics(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Game Center held-job limit is invalid.');
        }
        $statement = $this->database->prepare(
            'SELECT id, publication_kind, vendor_identifier, attempt_count, '
            . 'last_http_status, last_error_code, last_error, updated_at '
            . 'FROM game_center_publication_outbox '
            . "WHERE pre_released = :pre_released AND state = 'held' "
            . 'ORDER BY updated_at ASC, id ASC LIMIT :limit'
        );
        $statement->bindValue(':pre_released', $this->preReleased ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'publicationKind' => (string) $row['publication_kind'],
                'vendorIdentifier' => (string) $row['vendor_identifier'],
                'attemptCount' => (int) $row['attempt_count'],
                'httpStatus' => $row['last_http_status'] === null
                    ? null
                    : (int) $row['last_http_status'],
                'errorCode' => is_string($row['last_error_code'])
                    ? $row['last_error_code']
                    : null,
                'diagnostic' => is_string($row['last_error'])
                    ? $row['last_error']
                    : null,
                'updatedAt' => (string) $row['updated_at'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Operator-only exact recovery after fixing the recorded Apple rejection.
     * Ordinary gameplay and backfill do not revive held jobs.
     */
    public function requeueHeldById(string $jobId): bool
    {
        if ($this->database->inTransaction()) {
            throw new \LogicException('Game Center held-job recovery owns its transaction.');
        }
        if (!Uuid::isValidV4($jobId)) {
            throw new \InvalidArgumentException('Game Center outbox job ID is invalid.');
        }
        $statement = $this->database->prepare(
            "UPDATE game_center_publication_outbox SET state = 'pending', "
            . 'attempt_count = 0, desired_revision = desired_revision + 1, '
            . 'available_at = :available_at, lock_token = NULL, locked_at = NULL, '
            . 'last_http_status = NULL, last_error_code = NULL, last_error = NULL, '
            . 'updated_at = :updated_at '
            . "WHERE id = :id AND pre_released = :pre_released AND state = 'held'"
        );
        $timestamp = self::timestamp();
        $statement->execute([
            'available_at' => $timestamp,
            'updated_at' => $timestamp,
            'id' => strtolower($jobId),
            'pre_released' => $this->preReleased ? 1 : 0,
        ]);
        return $statement->rowCount() === 1;
    }

    /** @return array<string, mixed>|null */
    public function claimNext(int $leaseSeconds = 120): ?array
    {
        if ($leaseSeconds < 30 || $leaseSeconds > 900) {
            throw new \InvalidArgumentException('Game Center lease duration is invalid.');
        }
        $now = self::timestamp();
        $stale = self::timestamp(microtime(true) - $leaseSeconds);
        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'SELECT * FROM game_center_publication_outbox WHERE pre_released = :pre_released '
                . "AND ((state IN ('pending','retry') AND available_at <= :available_at) "
                . "OR (state = 'processing' AND locked_at <= :stale_at)) "
                . 'ORDER BY available_at ASC, created_at ASC, id ASC LIMIT 1'
                . $this->forUpdate()
            );
            $statement->execute([
                'pre_released' => $this->preReleased ? 1 : 0,
                'available_at' => $now,
                'stale_at' => $stale,
            ]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                $this->database->commit();
                return null;
            }
            $lockToken = Uuid::v4();
            $update = $this->database->prepare(
                "UPDATE game_center_publication_outbox SET state = 'processing', "
                . 'attempt_count = attempt_count + 1, lock_token = :lock_token, '
                . 'locked_at = :locked_at, updated_at = :updated_at WHERE id = :id'
            );
            $update->execute([
                'lock_token' => $lockToken,
                'locked_at' => $now,
                'updated_at' => $now,
                'id' => $row['id'],
            ]);
            $this->database->commit();
            $row['state'] = 'processing';
            $row['attempt_count'] = (int) $row['attempt_count'] + 1;
            $row['lock_token'] = $lockToken;
            $row['locked_at'] = $now;
            return $this->normalizeJob($row);
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /**
     * Rechecks current PHP authority immediately before the HTTP request.
     *
     * @param array<string, mixed> $job
     * @return null|array{
     *   scopedPlayerId: string,
     *   vendorIdentifier: string,
     *   desiredValue: int
     * }
     */
    public function prepareClaimForDelivery(array $job): ?array
    {
        $id = self::jobString($job, 'id');
        $lockToken = self::jobString($job, 'lock_token');
        $revision = self::jobInteger($job, 'desired_revision');
        $jobPlayerId = self::normalizedPlayerId(self::jobString($job, 'player_id'));
        $this->database->beginTransaction();
        try {
            // Keep the same binding -> outbox lock order used by link,
            // disable, score, and moderation transactions.
            $binding = $this->binding($jobPlayerId, true);
            $statement = $this->database->prepare(
                'SELECT * FROM game_center_publication_outbox '
                . "WHERE id = :id AND state = 'processing' AND lock_token = :lock_token "
                . 'AND desired_revision = :desired_revision LIMIT 1' . $this->forUpdate()
            );
            $statement->execute([
                'id' => $id,
                'lock_token' => $lockToken,
                'desired_revision' => $revision,
            ]);
            $current = $statement->fetch();
            if (!is_array($current)) {
                $this->database->commit();
                return null;
            }
            $playerId = (string) $current['player_id'];
            if (
                !hash_equals($jobPlayerId, $playerId)
                || $binding === null
                || $binding['publication_enabled_at'] === null
                || $binding['publication_disabled_at'] !== null
            ) {
                $this->cancelClaim($id, $lockToken, $revision, 'PUBLICATION_UNAVAILABLE');
                $this->database->commit();
                return null;
            }
            $kind = (string) $current['publication_kind'];
            $desired = $current['desired_value'] === null
                ? null
                : (int) $current['desired_value'];
            if ($kind === 'leaderboard') {
                $vendorIdentifier = (string) $current['vendor_identifier'];
                if (
                    !GameCenterCatalog::supportsLeaderboardVendorIdentifier(
                        $vendorIdentifier,
                    )
                ) {
                    $this->cancelClaim(
                        $id,
                        $lockToken,
                        $revision,
                        'INVALID_LEADERBOARD_IDENTIFIER',
                    );
                    $this->database->commit();
                    return null;
                }
                $authoritative = $this->authoritativeLeaderboardScore(
                    $playerId,
                    $vendorIdentifier,
                );
                if ($authoritative === null) {
                    $this->markClaimNeedsReset($current, $vendorIdentifier);
                    $this->database->commit();
                    return null;
                }
                if ($desired !== $authoritative) {
                    $this->replaceClaimDesired($current, $authoritative);
                    $this->database->commit();
                    return null;
                }
            } elseif ($kind === 'achievement') {
                $achievementId = GameCenterCatalog::achievementIdForVendorIdentifier(
                    (string) $current['vendor_identifier'],
                );
                $unlocked = $this->database->prepare(
                    'SELECT 1 FROM player_achievements '
                    . 'WHERE player_id = :player_id AND achievement_key = :achievement_key LIMIT 1'
                );
                $unlocked->execute([
                    'player_id' => $playerId,
                    'achievement_key' => $achievementId,
                ]);
                if ($unlocked->fetchColumn() === false || $desired !== 100) {
                    $this->cancelClaim($id, $lockToken, $revision, 'ACHIEVEMENT_NOT_UNLOCKED');
                    $this->database->commit();
                    return null;
                }
            } else {
                $this->cancelClaim($id, $lockToken, $revision, 'INVALID_OUTBOX_KIND');
                $this->database->commit();
                return null;
            }
            $scopedPlayerId = $this->decrypt($playerId, $binding);
            $this->database->commit();
            return [
                'scopedPlayerId' => $scopedPlayerId,
                'vendorIdentifier' => (string) $current['vendor_identifier'],
                'desiredValue' => $desired
                    ?? throw new \RuntimeException('Game Center desired value is missing.'),
            ];
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /** @param array<string, mixed> $job */
    public function markSucceeded(array $job, string $appleSubmissionId): bool
    {
        if ($appleSubmissionId === '' || strlen($appleSubmissionId) > 255) {
            throw new \InvalidArgumentException('Apple submission identifier is invalid.');
        }
        $update = $this->database->prepare(
            "UPDATE game_center_publication_outbox SET state = 'succeeded', "
            . 'delivered_value = desired_value, apple_submission_id = :submission_id, '
            . 'last_http_status = 201, last_error_code = NULL, last_error = NULL, '
            . 'lock_token = NULL, locked_at = NULL, delivered_at = :delivered_at, '
            . 'updated_at = :updated_at '
            . "WHERE id = :id AND state = 'processing' AND lock_token = :lock_token "
            . 'AND desired_revision = :desired_revision'
        );
        $timestamp = self::timestamp();
        $update->execute([
            'submission_id' => $appleSubmissionId,
            'delivered_at' => $timestamp,
            'updated_at' => $timestamp,
            'id' => self::jobString($job, 'id'),
            'lock_token' => self::jobString($job, 'lock_token'),
            'desired_revision' => self::jobInteger($job, 'desired_revision'),
        ]);
        return $update->rowCount() === 1;
    }

    /** @param array<string, mixed> $job */
    public function markFailed(array $job, Throwable $error): bool
    {
        $attempt = max(1, self::jobInteger($job, 'attempt_count'));
        $retryable = !$error instanceof GameCenterAppleApiException || $error->retryable;
        $held = !$retryable || $attempt >= 12;
        $delaySeconds = !$held
            ? min(21_600, (2 ** min(12, $attempt)) * 15 + random_int(0, 30))
            : 0;
        $httpStatus = $error instanceof GameCenterAppleApiException
            ? $error->httpStatus
            : null;
        $appleCode = $error instanceof GameCenterAppleApiException
            ? $error->appleCode
            : null;
        $diagnostic = $error instanceof GameCenterAppleApiException
            ? $error->operatorDiagnostic()
            : 'Internal Game Center publisher failure.';
        $update = $this->database->prepare(
            'UPDATE game_center_publication_outbox SET state = :state, '
            . 'available_at = :available_at, lock_token = NULL, locked_at = NULL, '
            . 'last_http_status = :http_status, last_error_code = :error_code, '
            . 'last_error = :last_error, updated_at = :updated_at '
            . "WHERE id = :id AND state = 'processing' AND lock_token = :lock_token "
            . 'AND desired_revision = :desired_revision'
        );
        $update->bindValue(':state', $held ? 'held' : 'retry');
        $update->bindValue(':available_at', self::timestamp(microtime(true) + $delaySeconds));
        $update->bindValue(':http_status', $httpStatus, $httpStatus === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $update->bindValue(':error_code', $appleCode, $appleCode === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $update->bindValue(
            ':last_error',
            mb_strcut($diagnostic, 0, 500, 'UTF-8'),
        );
        $update->bindValue(':updated_at', self::timestamp());
        $update->bindValue(':id', self::jobString($job, 'id'));
        $update->bindValue(':lock_token', self::jobString($job, 'lock_token'));
        $update->bindValue(':desired_revision', self::jobInteger($job, 'desired_revision'), PDO::PARAM_INT);
        $update->execute();
        return $update->rowCount() === 1;
    }

    private function queueDesired(
        string $playerId,
        string $kind,
        string $vendorIdentifier,
        int $desiredValue,
    ): void {
        $existing = $this->outboxRow($playerId, $kind, $vendorIdentifier, true);
        if (is_array($existing)) {
            $sameDesired = $existing['desired_value'] !== null
                && (int) $existing['desired_value'] === $desiredValue;
            $state = (string) $existing['state'];
            if (
                $sameDesired
                && in_array($state, ['pending', 'processing', 'retry'], true)
            ) {
                return;
            }
            if ($state === 'held') {
                return;
            }
            if (
                $sameDesired
                && $state === 'succeeded'
                && $existing['delivered_value'] !== null
                && (int) $existing['delivered_value'] === $desiredValue
            ) {
                return;
            }
            $timestamp = self::timestamp();
            $update = $this->database->prepare(
                "UPDATE game_center_publication_outbox SET desired_value = :desired_value, "
                . "desired_revision = desired_revision + 1, state = 'pending', attempt_count = 0, "
                . 'available_at = :available_at, lock_token = NULL, locked_at = NULL, '
                . 'last_http_status = NULL, last_error_code = NULL, last_error = NULL, '
                . 'updated_at = :updated_at WHERE id = :id'
            );
            $update->execute([
                'desired_value' => $desiredValue,
                'available_at' => $timestamp,
                'updated_at' => $timestamp,
                'id' => $existing['id'],
            ]);
            return;
        }

        $timestamp = self::timestamp();
        $insert = $this->database->prepare(
            'INSERT INTO game_center_publication_outbox '
            . '(id, player_id, publication_kind, vendor_identifier, pre_released, '
            . 'desired_value, desired_revision, state, available_at, created_at, updated_at) '
            . "VALUES (:id, :player_id, :publication_kind, :vendor_identifier, :pre_released, "
            . ":desired_value, 1, 'pending', :available_at, :created_at, :updated_at)"
        );
        $insert->execute([
            'id' => Uuid::v4(),
            'player_id' => $playerId,
            'publication_kind' => $kind,
            'vendor_identifier' => $vendorIdentifier,
            'pre_released' => $this->preReleased ? 1 : 0,
            'desired_value' => $desiredValue,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function markLeaderboardWithoutDesiredScore(
        string $playerId,
        string $vendorIdentifier,
    ): void {
        $existing = $this->outboxRow(
            $playerId,
            'leaderboard',
            $vendorIdentifier,
            true,
        );
        if (!is_array($existing)) {
            return;
        }
        $state = $existing['delivered_value'] === null ? 'cancelled' : 'needs_reset';
        $update = $this->database->prepare(
            'UPDATE game_center_publication_outbox SET desired_value = NULL, '
            . 'desired_revision = desired_revision + 1, state = :state, lock_token = NULL, '
            . 'locked_at = NULL, last_error_code = :error_code, last_error = :last_error, '
            . 'updated_at = :updated_at WHERE id = :id'
        );
        $update->execute([
            'state' => $state,
            'error_code' => $state === 'needs_reset' ? 'APPLE_SCORE_RESET_REQUIRED' : 'NO_VERIFIED_SCORE',
            'last_error' => $state === 'needs_reset'
                ? 'No verified score remains for this leaderboard; '
                    . 'Apple has no documented per-player reset in this API.'
                : 'No verified score remains for this leaderboard.',
            'updated_at' => self::timestamp(),
            'id' => $existing['id'],
        ]);
    }

    /** @param array<string, mixed> $current */
    private function replaceClaimDesired(array $current, int $desiredValue): void
    {
        $update = $this->database->prepare(
            "UPDATE game_center_publication_outbox SET desired_value = :desired_value, "
            . "desired_revision = desired_revision + 1, state = 'pending', attempt_count = 0, "
            . 'available_at = :available_at, lock_token = NULL, locked_at = NULL, '
            . 'last_http_status = NULL, last_error_code = NULL, last_error = NULL, '
            . 'updated_at = :updated_at WHERE id = :id AND lock_token = :lock_token'
        );
        $timestamp = self::timestamp();
        $update->execute([
            'desired_value' => $desiredValue,
            'available_at' => $timestamp,
            'updated_at' => $timestamp,
            'id' => $current['id'],
            'lock_token' => $current['lock_token'],
        ]);
    }

    /** @param array<string, mixed> $current */
    private function markClaimNeedsReset(
        array $current,
        string $vendorIdentifier,
    ): void {
        if (!GameCenterCatalog::supportsLeaderboardVendorIdentifier($vendorIdentifier)) {
            throw new \InvalidArgumentException(
                'Unknown Game Center leaderboard identifier.',
            );
        }
        $state = $current['delivered_value'] === null ? 'cancelled' : 'needs_reset';
        $update = $this->database->prepare(
            'UPDATE game_center_publication_outbox SET desired_value = NULL, '
            . 'desired_revision = desired_revision + 1, state = :state, lock_token = NULL, '
            . 'locked_at = NULL, last_error_code = :error_code, last_error = :last_error, '
            . 'updated_at = :updated_at WHERE id = :id AND lock_token = :lock_token'
        );
        $update->execute([
            'state' => $state,
            'error_code' => $state === 'needs_reset' ? 'APPLE_SCORE_RESET_REQUIRED' : 'NO_VERIFIED_SCORE',
            'last_error' => $state === 'needs_reset'
                ? 'No verified score remains for this leaderboard; '
                    . 'Apple has no documented per-player reset in this API.'
                : 'No verified score remains for this leaderboard.',
            'updated_at' => self::timestamp(),
            'id' => $current['id'],
            'lock_token' => $current['lock_token'],
        ]);
    }

    private function cancelClaim(
        string $id,
        string $lockToken,
        int $revision,
        string $reason,
    ): void {
        $update = $this->database->prepare(
            "UPDATE game_center_publication_outbox SET state = 'cancelled', "
            . 'lock_token = NULL, locked_at = NULL, last_error_code = :error_code, '
            . 'last_error = :last_error, updated_at = :updated_at '
            . "WHERE id = :id AND state = 'processing' AND lock_token = :lock_token "
            . 'AND desired_revision = :desired_revision'
        );
        $update->execute([
            'error_code' => $reason,
            'last_error' => 'The authoritative Game Center publication state is no longer eligible.',
            'updated_at' => self::timestamp(),
            'id' => $id,
            'lock_token' => $lockToken,
            'desired_revision' => $revision,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function affectedBindings(
        string $playerId,
        string $teamPlayerIdHash,
        string $gamePlayerIdHash,
        bool $forUpdate,
    ): array {
        $statement = $this->database->prepare(
            'SELECT * FROM player_game_center_bindings '
            . 'WHERE player_id = :player_id '
            . 'OR team_player_id_hash = :team_player_id_hash '
            . 'OR game_player_id_hash = :game_player_id_hash '
            . 'ORDER BY player_id ASC'
            . ($forUpdate ? $this->forUpdate() : '')
        );
        $statement->bindValue(':player_id', $playerId);
        $statement->bindValue(':team_player_id_hash', $teamPlayerIdHash, PDO::PARAM_LOB);
        $statement->bindValue(':game_player_id_hash', $gamePlayerIdHash, PDO::PARAM_LOB);
        $statement->execute();
        return array_values(array_filter(
            $statement->fetchAll(),
            static fn (mixed $row): bool => is_array($row),
        ));
    }

    /** @return list<string> */
    private function affectedPlayerIds(
        string $playerId,
        string $teamPlayerIdHash,
        string $gamePlayerIdHash,
        bool $forUpdate,
    ): array {
        $ids = [$playerId];
        foreach ($this->affectedBindings(
            $playerId,
            $teamPlayerIdHash,
            $gamePlayerIdHash,
            $forUpdate,
        ) as $binding) {
            $boundPlayerId = $binding['player_id'] ?? null;
            if (is_string($boundPlayerId)) {
                $ids[] = self::normalizedPlayerId($boundPlayerId);
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        return $ids;
    }

    private function consumeAssertion(string $assertionHash, string $expiresAt): void
    {
        $this->database->exec(
            'DELETE FROM game_center_assertion_uses WHERE expires_at <= CURRENT_TIMESTAMP'
        );
        $statement = $this->database->prepare(
            'INSERT INTO game_center_assertion_uses (assertion_hash, expires_at) '
            . 'VALUES (:assertion_hash, :expires_at)'
        );
        $statement->bindValue(':assertion_hash', $assertionHash, PDO::PARAM_LOB);
        $statement->bindValue(':expires_at', $expiresAt);
        try {
            $statement->execute();
        } catch (\PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new ApiException(409, 'This Game Center proof was already used.');
            }
            throw $error;
        }
    }

    /** @param list<string> $playerIds */
    private function revisionCancelOutboxes(array $playerIds, string $timestamp): void
    {
        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $lock = $this->database->prepare(
            'SELECT id FROM game_center_publication_outbox '
            . "WHERE player_id IN ({$placeholders}) ORDER BY player_id ASC, id ASC"
            . $this->forUpdate()
        );
        $lock->execute($playerIds);
        $lock->fetchAll(PDO::FETCH_COLUMN);
        $statement = $this->database->prepare(
            'UPDATE game_center_publication_outbox SET '
            . 'desired_value = NULL, delivered_value = NULL, '
            . 'desired_revision = desired_revision + 1, '
            . "state = 'cancelled', attempt_count = 0, "
            . 'available_at = ?, updated_at = ?, '
            . 'lock_token = NULL, locked_at = NULL, apple_submission_id = NULL, '
            . 'delivered_at = NULL, last_http_status = NULL, '
            . 'last_error_code = NULL, last_error = NULL '
            . "WHERE player_id IN ({$placeholders})"
        );
        $statement->execute([$timestamp, $timestamp, ...$playerIds]);
    }

    /** @param list<string> $playerIds */
    private function deleteBindings(array $playerIds): void
    {
        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $statement = $this->database->prepare(
            "DELETE FROM player_game_center_bindings WHERE player_id IN ({$placeholders})"
        );
        $statement->execute($playerIds);
    }

    /** @param list<string> $playerIds */
    private function lockPlayers(array $playerIds, string $currentPlayerId): void
    {
        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $statement = $this->database->prepare(
            "SELECT id FROM players WHERE id IN ({$placeholders}) ORDER BY id ASC"
            . $this->forUpdate()
        );
        $statement->execute($playerIds);
        $found = array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        sort($found, SORT_STRING);
        if (!in_array($currentPlayerId, $found, true)) {
            throw new ApiException(401, 'Sign in again to continue.');
        }
        if (array_diff($playerIds, $found) !== []) {
            throw new GameCenterAssignmentRetry(
                'A displaced Game Center profile disappeared during reassignment.',
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function binding(string $playerId, bool $forUpdate): ?array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM player_game_center_bindings WHERE player_id = :player_id LIMIT 1'
            . ($forUpdate ? $this->forUpdate() : '')
        );
        $statement->execute(['player_id' => $playerId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function publicationIsActive(string $playerId, bool $forUpdate): bool
    {
        $binding = $this->binding($playerId, $forUpdate);
        return $binding !== null
            && $binding['publication_enabled_at'] !== null
            && $binding['publication_disabled_at'] === null
            && is_string($binding['game_player_id_hash'] ?? null)
            && is_string($binding['game_player_id_ciphertext'] ?? null)
            && is_string($binding['game_player_id_iv'] ?? null)
            && is_string($binding['game_player_id_tag'] ?? null);
    }

    private function bestVerifiedArcadeScore(string $playerId): ?int
    {
        $statement = $this->database->prepare(
            'SELECT MAX(score) FROM leaderboard_entries WHERE player_id = :player_id '
            . "AND mode = 'normal' AND verification_status = 'verified'"
        );
        $statement->execute(['player_id' => $playerId]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    private function bestVerifiedMultiplayerScore(string $playerId): ?int
    {
        $statement = $this->database->prepare(
            'SELECT MAX(score) FROM multiplayer_results WHERE player_id = :player_id '
            . "AND verification_status = 'verified'"
        );
        $statement->execute(['player_id' => $playerId]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    private function authoritativeLeaderboardScore(
        string $playerId,
        string $vendorIdentifier,
    ): ?int {
        return match ($vendorIdentifier) {
            GameCenterCatalog::LEADERBOARD_ARCADE_VERIFIED =>
                $this->bestVerifiedArcadeScore($playerId),
            GameCenterCatalog::LEADERBOARD_MULTIPLAYER_VERIFIED =>
                $this->bestVerifiedMultiplayerScore($playerId),
            default => throw new \InvalidArgumentException(
                'Unknown Game Center leaderboard identifier.',
            ),
        };
    }

    /** @return array<string, mixed>|null */
    private function outboxRow(
        string $playerId,
        string $kind,
        string $vendorIdentifier,
        bool $forUpdate,
    ): ?array {
        $statement = $this->database->prepare(
            'SELECT * FROM game_center_publication_outbox '
            . 'WHERE player_id = :player_id AND publication_kind = :publication_kind '
            . 'AND vendor_identifier = :vendor_identifier AND pre_released = :pre_released LIMIT 1'
            . ($forUpdate ? $this->forUpdate() : '')
        );
        $statement->execute([
            'player_id' => $playerId,
            'publication_kind' => $kind,
            'vendor_identifier' => $vendorIdentifier,
            'pre_released' => $this->preReleased ? 1 : 0,
        ]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array{string, string, string} */
    private function encrypt(
        string $playerId,
        string $teamPlayerIdHash,
        string $gamePlayerId,
    ): array {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $gamePlayerId,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->additionalData($playerId, $teamPlayerIdHash),
            16,
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new ApiException(503, 'Game Center publication identity could not be encrypted.');
        }
        return [$ciphertext, $iv, $tag];
    }

    /** @param array<string, mixed> $binding */
    private function decrypt(string $playerId, array $binding): string
    {
        foreach ([
            'team_player_id_hash',
            'game_player_id_ciphertext',
            'game_player_id_iv',
            'game_player_id_tag',
        ] as $field) {
            if (!is_string($binding[$field] ?? null)) {
                throw new \RuntimeException('Game Center publication identity is incomplete.');
            }
        }
        $plaintext = openssl_decrypt(
            $binding['game_player_id_ciphertext'],
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $binding['game_player_id_iv'],
            $binding['game_player_id_tag'],
            $this->additionalData($playerId, $binding['team_player_id_hash']),
        );
        if (!is_string($plaintext) || $plaintext === '') {
            throw new \RuntimeException('Game Center publication identity could not be decrypted.');
        }
        return self::normalizedGamePlayerId($plaintext);
    }

    private function additionalData(string $playerId, string $teamPlayerIdHash): string
    {
        return "pimpopom-game-center-scoped-player-v1\0"
            . $playerId . "\0" . $teamPlayerIdHash;
    }

    private static function publicationLockName(string $playerId): string
    {
        return 'pimpopom-gc-player-' . substr(hash('sha256', $playerId), 0, 40);
    }

    private function forUpdate(): string
    {
        return $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
    }

    private function requireTransaction(): void
    {
        if (!$this->database->inTransaction()) {
            throw new \LogicException('Game Center publication requires an active transaction.');
        }
    }

    private function rollBack(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }

    private static function normalizedPlayerId(string $playerId): string
    {
        $playerId = strtolower(trim($playerId));
        if (!Uuid::isValidV4($playerId)) {
            throw new ApiException(401, 'Sign in again to continue.');
        }
        return $playerId;
    }

    private static function normalizedGamePlayerId(string $gamePlayerId): string
    {
        $gamePlayerId = trim($gamePlayerId);
        if (
            $gamePlayerId === ''
            || strlen($gamePlayerId) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $gamePlayerId) === 1
        ) {
            throw new ApiException(400, 'Game Center gamePlayerID is invalid.');
        }
        return $gamePlayerId;
    }

    /** @param array<string, mixed> $job */
    private function normalizeJob(array $job): array
    {
        foreach (['desired_value', 'delivered_value'] as $field) {
            if ($job[$field] !== null) {
                $job[$field] = (int) $job[$field];
            }
        }
        foreach (['desired_revision', 'attempt_count', 'pre_released'] as $field) {
            $job[$field] = (int) $job[$field];
        }
        return $job;
    }

    /** @param array<string, mixed> $job */
    private static function jobString(array $job, string $field): string
    {
        $value = $job[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Game Center outbox job is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $job */
    private static function jobInteger(array $job, string $field): int
    {
        $value = $job[$field] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException('Game Center outbox job is invalid.');
        }
        return (int) $value;
    }

    private static function timestamp(?float $seconds = null): string
    {
        $seconds ??= microtime(true);
        $whole = (int) floor($seconds);
        $milliseconds = (int) floor(($seconds - $whole) * 1_000);
        return gmdate('Y-m-d H:i:s', $whole) . sprintf('.%03d', $milliseconds);
    }
}

final class GameCenterAssignmentRetry extends \RuntimeException
{
}
