<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

/**
 * Maps verified external identities to the one internal player UUID that owns
 * profile, wallet, StoreKit, leaderboard, achievement, and cosmetic state.
 *
 * This service never merges two player UUIDs and never infers identity from an
 * email address, nickname, device, StoreKit token, or Game Center display data.
 */
final class PlayerIdentityService
{
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_APPLE = 'apple';

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * @param null|callable(string): void $beforeCommit
     * @return array{playerId: string, created: bool}
     */
    public function loginOrRegister(
        string $provider,
        string $subject,
        bool $allowCreate,
        ?callable $beforeCommit = null,
    ): array {
        return $this->loginOrRegisterAttempt(
            $provider,
            $subject,
            $allowCreate,
            $beforeCommit,
            true,
        );
    }

    /**
     * @param null|callable(string): void $beforeCommit
     * @return array{playerId: string, created: bool}
     */
    private function loginOrRegisterAttempt(
        string $provider,
        string $subject,
        bool $allowCreate,
        ?callable $beforeCommit,
        bool $retryOnConflict,
    ): array {
        [$provider, $subject, $subjectHash] = $this->normalizedIdentity($provider, $subject);
        $now = self::databaseTimestamp();

        $this->begin();
        try {
            $existingPlayerId = $this->playerIdForSubject($provider, $subjectHash, true);
            if ($existingPlayerId !== null) {
                $this->touchIdentityAndPlayer($provider, $subjectHash, $existingPlayerId, $now);
                if ($beforeCommit !== null) {
                    $beforeCommit($existingPlayerId);
                }
                $this->database->commit();
                return ['playerId' => $existingPlayerId, 'created' => false];
            }
            if (!$allowCreate) {
                throw new ApiException(
                    409,
                    'This sign-in is not linked to a PimPoPom profile. Sign in with an existing method or explicitly create a new profile.',
                );
            }

            $playerId = Uuid::v4();
            $insertPlayer = $this->database->prepare(
                'INSERT INTO players (id, google_subject_hash, nickname, last_login_at) '
                . 'VALUES (:id, :google_subject_hash, :nickname, :last_login_at)'
            );
            $insertPlayer->bindValue(':id', $playerId);
            if ($provider === self::PROVIDER_GOOGLE) {
                $insertPlayer->bindValue(':google_subject_hash', $subjectHash, PDO::PARAM_LOB);
            } else {
                $insertPlayer->bindValue(':google_subject_hash', null, PDO::PARAM_NULL);
            }
            $insertPlayer->bindValue(':nickname', Nickname::anonymous());
            $insertPlayer->bindValue(':last_login_at', $now);
            $insertPlayer->execute();

            $this->insertIdentity($provider, $subjectHash, $playerId, $now);
            if ($beforeCommit !== null) {
                $beforeCommit($playerId);
            }
            $this->database->commit();
            return ['playerId' => $playerId, 'created' => true];
        } catch (PDOException $error) {
            $this->rollBack();
            if ($error->getCode() === '23000' && $retryOnConflict) {
                // A concurrent registration/link may have won the unique
                // provider-subject constraint. Resolve that winner instead of
                // leaving or using the tentative player row.
                return $this->loginOrRegisterAttempt(
                    $provider,
                    $subject,
                    false,
                    $beforeCommit,
                    false,
                );
            }
            if ($error->getCode() === '23000') {
                throw new ApiException(
                    409,
                    'That sign-in was linked elsewhere while this request was being processed.',
                );
            }
            throw $error;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    public function reauthenticate(
        string $playerId,
        string $provider,
        string $subject,
        ?callable $beforeCommit = null,
    ): void {
        $playerId = $this->normalizedPlayerId($playerId);
        [$provider, , $subjectHash] = $this->normalizedIdentity($provider, $subject);
        $now = self::databaseTimestamp();

        $this->begin();
        try {
            $this->lockPlayer($playerId);
            $owner = $this->playerIdForSubject($provider, $subjectHash, true);
            if ($owner === null || !hash_equals($playerId, $owner)) {
                throw new ApiException(
                    409,
                    'This sign-in belongs to a different PimPoPom profile.',
                );
            }
            $this->touchIdentityAndPlayer($provider, $subjectHash, $playerId, $now);
            if ($beforeCommit !== null) {
                $beforeCommit($playerId);
            }
            $this->database->commit();
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /**
     * @param null|callable(string): void $beforeCommit
     * @return array{playerId: string, linked: bool}
     */
    public function linkPrimary(
        string $playerId,
        string $provider,
        string $subject,
        ?callable $beforeCommit = null,
    ): array {
        $playerId = $this->normalizedPlayerId($playerId);
        [$provider, , $subjectHash] = $this->normalizedIdentity($provider, $subject);
        $now = self::databaseTimestamp();

        $this->begin();
        try {
            $this->lockPlayer($playerId);
            $owner = $this->playerIdForSubject($provider, $subjectHash, true);
            if ($owner !== null && !hash_equals($playerId, $owner)) {
                throw new ApiException(
                    409,
                    'This sign-in is already linked to another PimPoPom profile.',
                );
            }

            $existingHash = $this->subjectHashForPlayerProvider($playerId, $provider, true);
            if ($existingHash !== null && !hash_equals($existingHash, $subjectHash)) {
                throw new ApiException(
                    409,
                    'This PimPoPom profile already has a different sign-in for that provider.',
                );
            }

            $linked = $owner === null;
            if ($linked) {
                $this->insertIdentity($provider, $subjectHash, $playerId, $now);
                if ($provider === self::PROVIDER_GOOGLE) {
                    $legacy = $this->database->prepare(
                        'UPDATE players SET google_subject_hash = :subject_hash WHERE id = :player_id'
                    );
                    $legacy->bindValue(':subject_hash', $subjectHash, PDO::PARAM_LOB);
                    $legacy->bindValue(':player_id', $playerId);
                    $legacy->execute();
                }
            }
            $this->touchIdentityAndPlayer($provider, $subjectHash, $playerId, $now);
            if ($beforeCommit !== null) {
                $beforeCommit($playerId);
            }
            $this->database->commit();
            return ['playerId' => $playerId, 'linked' => $linked];
        } catch (PDOException $error) {
            $this->rollBack();
            if ($error->getCode() === '23000') {
                throw new ApiException(
                    409,
                    'That sign-in was linked elsewhere while this request was being processed.',
                );
            }
            throw $error;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /** @return array{playerId: string, linked: bool} */
    public function linkGameCenter(string $playerId, GameCenterIdentity $identity): array
    {
        $playerId = $this->normalizedPlayerId($playerId);
        $teamPlayerHash = hash(
            'sha256',
            "game_center\0" . $identity->teamPlayerId,
            true,
        );
        $now = self::databaseTimestamp();
        $replayExpiresAt = gmdate('Y-m-d H:i:s', time() + 600);

        $this->begin();
        try {
            $this->database->exec(
                'DELETE FROM game_center_assertion_uses WHERE expires_at <= CURRENT_TIMESTAMP'
            );
            $replay = $this->database->prepare(
                'INSERT INTO game_center_assertion_uses (assertion_hash, expires_at) '
                . 'VALUES (:assertion_hash, :expires_at)'
            );
            $replay->bindValue(':assertion_hash', $identity->assertionHash, PDO::PARAM_LOB);
            $replay->bindValue(':expires_at', $replayExpiresAt);
            $replay->execute();

            $this->lockPlayer($playerId);
            $teamOwner = $this->playerIdForGameCenterHash($teamPlayerHash, true);
            if ($teamOwner !== null && !hash_equals($playerId, $teamOwner)) {
                throw new ApiException(
                    409,
                    'This Game Center account is already linked to another PimPoPom profile.',
                );
            }
            $playerHash = $this->gameCenterHashForPlayer($playerId, true);
            if ($playerHash !== null && !hash_equals($playerHash, $teamPlayerHash)) {
                throw new ApiException(
                    409,
                    'This PimPoPom profile already has a different Game Center account.',
                );
            }

            $linked = $teamOwner === null;
            if ($linked) {
                $insert = $this->database->prepare(
                    'INSERT INTO player_game_center_bindings '
                    . '(player_id, team_player_id_hash, linked_at, last_verified_at) '
                    . 'VALUES (:player_id, :team_player_id_hash, :linked_at, :last_verified_at)'
                );
                $insert->bindValue(':player_id', $playerId);
                $insert->bindValue(':team_player_id_hash', $teamPlayerHash, PDO::PARAM_LOB);
                $insert->bindValue(':linked_at', $now);
                $insert->bindValue(':last_verified_at', $now);
                $insert->execute();
            } else {
                $update = $this->database->prepare(
                    'UPDATE player_game_center_bindings SET last_verified_at = :last_verified_at '
                    . 'WHERE player_id = :player_id'
                );
                $update->execute(['last_verified_at' => $now, 'player_id' => $playerId]);
            }
            $this->database->commit();
            return ['playerId' => $playerId, 'linked' => $linked];
        } catch (PDOException $error) {
            $this->rollBack();
            if ($error->getCode() === '23000') {
                throw new ApiException(
                    409,
                    'This Game Center proof was already used or its account was linked elsewhere.',
                );
            }
            throw $error;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /** @return array{google: bool, apple: bool, gameCenter: bool} */
    public function bindings(string $playerId): array
    {
        $playerId = $this->normalizedPlayerId($playerId);
        $statement = $this->database->prepare(
            'SELECT provider FROM player_identities WHERE player_id = :player_id'
        );
        $statement->execute(['player_id' => $playerId]);
        $providers = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);

        $gameCenter = $this->database->prepare(
            'SELECT 1 FROM player_game_center_bindings WHERE player_id = :player_id LIMIT 1'
        );
        $gameCenter->execute(['player_id' => $playerId]);
        return [
            'google' => isset($providers[self::PROVIDER_GOOGLE]),
            'apple' => isset($providers[self::PROVIDER_APPLE]),
            'gameCenter' => $gameCenter->fetchColumn() !== false,
        ];
    }

    /** @return array{string, string, string} */
    private function normalizedIdentity(string $provider, string $subject): array
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, [self::PROVIDER_GOOGLE, self::PROVIDER_APPLE], true)) {
            throw new \InvalidArgumentException('Unsupported primary identity provider.');
        }
        if (
            $subject === ''
            || strlen($subject) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1
        ) {
            throw new ApiException(401, 'The sign-in identity is invalid.');
        }
        return [$provider, $subject, self::subjectHash($provider, $subject)];
    }

    public static function subjectHash(string $provider, string $subject): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, [self::PROVIDER_GOOGLE, self::PROVIDER_APPLE], true)) {
            throw new \InvalidArgumentException('Unsupported primary identity provider.');
        }
        if (
            $subject === ''
            || strlen($subject) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1
        ) {
            throw new ApiException(401, 'The sign-in identity is invalid.');
        }
        return hash('sha256', $provider . "\0" . $subject, true);
    }

    private function normalizedPlayerId(string $playerId): string
    {
        $playerId = strtolower(trim($playerId));
        if (!Uuid::isValidV4($playerId)) {
            throw new ApiException(401, 'Sign in again to continue.');
        }
        return $playerId;
    }

    private function insertIdentity(
        string $provider,
        string $subjectHash,
        string $playerId,
        string $timestamp,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO player_identities '
            . '(provider, subject_hash, player_id, linked_at, last_authenticated_at) '
            . 'VALUES (:provider, :subject_hash, :player_id, :linked_at, :last_authenticated_at)'
        );
        $statement->bindValue(':provider', $provider);
        $statement->bindValue(':subject_hash', $subjectHash, PDO::PARAM_LOB);
        $statement->bindValue(':player_id', $playerId);
        $statement->bindValue(':linked_at', $timestamp);
        $statement->bindValue(':last_authenticated_at', $timestamp);
        $statement->execute();
    }

    private function playerIdForSubject(
        string $provider,
        string $subjectHash,
        bool $forUpdate,
    ): ?string {
        $statement = $this->database->prepare(
            'SELECT player_id FROM player_identities '
            . 'WHERE provider = :provider AND subject_hash = :subject_hash LIMIT 1'
            . $this->forUpdate($forUpdate)
        );
        $statement->bindValue(':provider', $provider);
        $statement->bindValue(':subject_hash', $subjectHash, PDO::PARAM_LOB);
        $statement->execute();
        $playerId = $statement->fetchColumn();
        return is_string($playerId) ? strtolower($playerId) : null;
    }

    private function subjectHashForPlayerProvider(
        string $playerId,
        string $provider,
        bool $forUpdate,
    ): ?string {
        $statement = $this->database->prepare(
            'SELECT subject_hash FROM player_identities '
            . 'WHERE player_id = :player_id AND provider = :provider LIMIT 1'
            . $this->forUpdate($forUpdate)
        );
        $statement->execute(['player_id' => $playerId, 'provider' => $provider]);
        $hash = $statement->fetchColumn();
        return is_string($hash) && strlen($hash) === 32 ? $hash : null;
    }

    private function playerIdForGameCenterHash(string $hash, bool $forUpdate): ?string
    {
        $statement = $this->database->prepare(
            'SELECT player_id FROM player_game_center_bindings '
            . 'WHERE team_player_id_hash = :team_player_id_hash LIMIT 1'
            . $this->forUpdate($forUpdate)
        );
        $statement->bindValue(':team_player_id_hash', $hash, PDO::PARAM_LOB);
        $statement->execute();
        $playerId = $statement->fetchColumn();
        return is_string($playerId) ? strtolower($playerId) : null;
    }

    private function gameCenterHashForPlayer(string $playerId, bool $forUpdate): ?string
    {
        $statement = $this->database->prepare(
            'SELECT team_player_id_hash FROM player_game_center_bindings '
            . 'WHERE player_id = :player_id LIMIT 1'
            . $this->forUpdate($forUpdate)
        );
        $statement->execute(['player_id' => $playerId]);
        $hash = $statement->fetchColumn();
        return is_string($hash) && strlen($hash) === 32 ? $hash : null;
    }

    private function touchIdentityAndPlayer(
        string $provider,
        string $subjectHash,
        string $playerId,
        string $timestamp,
    ): void {
        $identity = $this->database->prepare(
            'UPDATE player_identities SET last_authenticated_at = :last_authenticated_at '
            . 'WHERE provider = :provider AND subject_hash = :subject_hash AND player_id = :player_id'
        );
        $identity->bindValue(':last_authenticated_at', $timestamp);
        $identity->bindValue(':provider', $provider);
        $identity->bindValue(':subject_hash', $subjectHash, PDO::PARAM_LOB);
        $identity->bindValue(':player_id', $playerId);
        $identity->execute();
        $player = $this->database->prepare(
            'UPDATE players SET last_login_at = :last_login_at WHERE id = :player_id'
        );
        $player->execute(['last_login_at' => $timestamp, 'player_id' => $playerId]);
    }

    private function lockPlayer(string $playerId): void
    {
        $statement = $this->database->prepare(
            'SELECT id FROM players WHERE id = :player_id LIMIT 1' . $this->forUpdate(true)
        );
        $statement->execute(['player_id' => $playerId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException(401, 'Sign in again to continue.');
        }
    }

    private function forUpdate(bool $requested): string
    {
        return $requested && $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            ? ' FOR UPDATE'
            : '';
    }

    private function begin(): void
    {
        if ($this->database->inTransaction()) {
            throw new \InvalidArgumentException('Identity operations must own their database transaction.');
        }
        $this->database->beginTransaction();
    }

    private function rollBack(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }

    private static function databaseTimestamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
