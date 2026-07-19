<?php

declare(strict_types=1);

namespace SpeedyTapper;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Resolves opaque PHP-session authentication tokens to player identities.
 *
 * Only a one-way digest of the token is persisted. Deleting a player removes
 * every mapping through the database foreign key, so any surviving PHP session
 * file contains no usable player identity and fails closed on its next lookup.
 */
final class SessionRegistry
{
    private const AUTH_ID_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';
    private const SESSION_LIFETIME_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(private readonly PDO $database)
    {
    }

    public function resolve(string $authId): ?string
    {
        $hash = $this->authIdHash($authId);
        if ($hash === null) {
            return null;
        }

        $statement = $this->database->prepare(
            'SELECT player_id FROM player_sessions '
            . 'WHERE session_auth_hash = :session_auth_hash '
            . 'AND expires_at > CURRENT_TIMESTAMP LIMIT 1'
        );
        $statement->bindValue(':session_auth_hash', $hash, PDO::PARAM_LOB);
        $statement->execute();
        $playerId = $statement->fetchColumn();
        if (!is_string($playerId) || !Uuid::isValidV4($playerId)) {
            $this->revoke($authId);
            return null;
        }

        return strtolower($playerId);
    }

    public function rotate(?string $previousAuthId, string $newAuthId, string $playerId): void
    {
        $playerId = strtolower(trim($playerId));
        if (!Uuid::isValidV4($playerId)) {
            throw new InvalidArgumentException('Session player ID must be a version 4 UUID.');
        }
        $newHash = $this->authIdHash($newAuthId);
        if ($newHash === null) {
            throw new InvalidArgumentException('Session authentication ID is invalid.');
        }
        $previousHash = $previousAuthId === null ? null : $this->authIdHash($previousAuthId);
        if ($previousAuthId !== null && $previousHash === null) {
            throw new InvalidArgumentException('Previous session authentication ID is invalid.');
        }
        if ($this->database->inTransaction()) {
            throw new InvalidArgumentException('Session rotation must own its database transaction.');
        }

        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::SESSION_LIFETIME_SECONDS);
        $this->database->beginTransaction();
        try {
            $this->database->exec(
                'DELETE FROM player_sessions WHERE expires_at <= CURRENT_TIMESTAMP'
            );
            $insert = $this->database->prepare(
                'INSERT INTO player_sessions '
                . '(session_auth_hash, player_id, expires_at) '
                . 'VALUES (:session_auth_hash, :player_id, :expires_at)'
            );
            $insert->bindValue(':session_auth_hash', $newHash, PDO::PARAM_LOB);
            $insert->bindValue(':player_id', $playerId);
            $insert->bindValue(':expires_at', $expiresAt);
            $insert->execute();

            if ($previousHash !== null && !hash_equals($previousHash, $newHash)) {
                $delete = $this->database->prepare(
                    'DELETE FROM player_sessions WHERE session_auth_hash = :session_auth_hash'
                );
                $delete->bindValue(':session_auth_hash', $previousHash, PDO::PARAM_LOB);
                $delete->execute();
            }
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function revoke(string $authId): void
    {
        $hash = $this->authIdHash($authId);
        if ($hash === null) {
            return;
        }
        $statement = $this->database->prepare(
            'DELETE FROM player_sessions WHERE session_auth_hash = :session_auth_hash'
        );
        $statement->bindValue(':session_auth_hash', $hash, PDO::PARAM_LOB);
        $statement->execute();
    }

    private function authIdHash(string $authId): ?string
    {
        if (preg_match(self::AUTH_ID_PATTERN, $authId) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($authId, '-_', '+/') . '=', true);
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            return null;
        }
        return hash('sha256', $authId, true);
    }
}
