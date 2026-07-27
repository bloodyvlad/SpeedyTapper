<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use Throwable;

/**
 * Owns the encrypted client-asserted gamePlayerID association and the
 * coalescing desired-state outbox. Apple does not sign this identifier in the
 * ordinary non-Apple-Arcade identity tuple; callers must first verify the
 * signed teamPlayerID and a fresh primary-authenticated link challenge.
 */
final class GameCenterPublicationRepository
{
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
     * @return array{enabled: bool, newlyBound: bool}
     */
    public function enableInCurrentTransaction(
        string $playerId,
        string $teamPlayerIdHash,
        string $gamePlayerId,
    ): array {
        $this->requireTransaction();
        $playerId = self::normalizedPlayerId($playerId);
        if (strlen($teamPlayerIdHash) !== 32) {
            throw new \InvalidArgumentException('Game Center team-player digest is invalid.');
        }
        $gamePlayerId = self::normalizedGamePlayerId($gamePlayerId);
        $gamePlayerIdHash = hash(
            'sha256',
            "game_center_game_player\0" . $gamePlayerId,
            true,
        );
        $binding = $this->binding($playerId, true);
        if ($binding === null) {
            throw new ApiException(409, 'Link Game Center identity before enabling publication.');
        }
        $storedTeamHash = $binding['team_player_id_hash'] ?? null;
        if (!is_string($storedTeamHash) || !hash_equals($storedTeamHash, $teamPlayerIdHash)) {
            throw new ApiException(409, 'Game Center identity changed during linking.');
        }
        $existingHash = $binding['game_player_id_hash'] ?? null;
        if (is_string($existingHash) && !hash_equals($existingHash, $gamePlayerIdHash)) {
            throw new ApiException(
                409,
                'This PimPoPom profile already publishes to a different Game Center player.',
            );
        }
        $owner = $this->playerForGamePlayerHash($gamePlayerIdHash, true);
        if ($owner !== null && !hash_equals($owner, $playerId)) {
            throw new ApiException(
                409,
                'This Game Center player already publishes another PimPoPom profile.',
            );
        }

        $newlyBound = !is_string($existingHash);
        $timestamp = self::timestamp();
        if ($newlyBound) {
            [$ciphertext, $iv, $tag] = $this->encrypt(
                $playerId,
                $teamPlayerIdHash,
                $gamePlayerId,
            );
            $update = $this->database->prepare(
                'UPDATE player_game_center_bindings SET '
                . 'game_player_id_hash = :game_player_id_hash, '
                . 'game_player_id_ciphertext = :ciphertext, game_player_id_iv = :iv, '
                . 'game_player_id_tag = :tag, publication_enabled_at = :enabled_at, '
                . 'publication_disabled_at = NULL WHERE player_id = :player_id'
            );
            $update->bindValue(':game_player_id_hash', $gamePlayerIdHash, PDO::PARAM_LOB);
            $update->bindValue(':ciphertext', $ciphertext, PDO::PARAM_LOB);
            $update->bindValue(':iv', $iv, PDO::PARAM_LOB);
            $update->bindValue(':tag', $tag, PDO::PARAM_LOB);
            $update->bindValue(':enabled_at', $timestamp);
            $update->bindValue(':player_id', $playerId);
            $update->execute();
        } else {
            $update = $this->database->prepare(
                'UPDATE player_game_center_bindings SET publication_enabled_at = :enabled_at, '
                . 'publication_disabled_at = NULL '
                . 'WHERE player_id = :player_id'
            );
            $update->execute(['enabled_at' => $timestamp, 'player_id' => $playerId]);
        }
        $this->backfillInCurrentTransaction($playerId);
        return ['enabled' => true, 'newlyBound' => $newlyBound];
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
        $playerId = self::normalizedPlayerId($playerId);
        if ($this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return $operation();
        }
        if ($this->database->inTransaction()) {
            throw new \LogicException('Acquire the Game Center player lock before a transaction.');
        }
        $lockName = 'pimpopom-gc-player-' . substr(hash('sha256', $playerId), 0, 40);
        $lock = $this->database->prepare('SELECT GET_LOCK(:lock_name, 25)');
        $lock->execute(['lock_name' => $lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new ApiException(
                503,
                'Game Center publication is busy. Please try again.',
            );
        }
        try {
            return $operation();
        } finally {
            $release = $this->database->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
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
        $this->requireTransaction();
        $playerId = self::normalizedPlayerId($playerId);
        if (!$this->publicationIsActive($playerId, true)) {
            return;
        }
        $best = $this->bestVerifiedArcadeScore($playerId);
        if ($best === null) {
            $this->markLeaderboardWithoutDesiredScore($playerId);
            return;
        }
        $this->queueDesired(
            $playerId,
            'leaderboard',
            GameCenterCatalog::LEADERBOARD_ARCADE_VERIFIED,
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
     * @return null|array{scopedPlayerId: string, desiredValue: int}
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
                $authoritative = $this->bestVerifiedArcadeScore($playerId);
                if ($authoritative === null) {
                    $this->markClaimNeedsReset($current);
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

    private function markLeaderboardWithoutDesiredScore(string $playerId): void
    {
        $existing = $this->outboxRow(
            $playerId,
            'leaderboard',
            GameCenterCatalog::LEADERBOARD_ARCADE_VERIFIED,
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
                ? 'No verified Arcade score remains; Apple has no documented per-player reset in this API.'
                : 'No verified Arcade score remains.',
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
    private function markClaimNeedsReset(array $current): void
    {
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
                ? 'No verified Arcade score remains; Apple has no documented per-player reset in this API.'
                : 'No verified Arcade score remains.',
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

    private function playerForGamePlayerHash(string $hash, bool $forUpdate): ?string
    {
        $statement = $this->database->prepare(
            'SELECT player_id FROM player_game_center_bindings '
            . 'WHERE game_player_id_hash = :game_player_id_hash LIMIT 1'
            . ($forUpdate ? $this->forUpdate() : '')
        );
        $statement->bindValue(':game_player_id_hash', $hash, PDO::PARAM_LOB);
        $statement->execute();
        $playerId = $statement->fetchColumn();
        return is_string($playerId) ? strtolower($playerId) : null;
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
