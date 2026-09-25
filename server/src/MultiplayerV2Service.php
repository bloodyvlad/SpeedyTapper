<?php

declare(strict_types=1);

namespace SpeedyTapper;

use Closure;
use PDO;
use Throwable;

/** Revocable v2 service access. No live gameplay, ranking or economy authority. */
final class MultiplayerV2Service
{
    public const PROTOCOL_VERSION = 2;
    public const RULESET = 'multiplayer-shared-arcade-v2';
    private const TICKET_SECONDS = 60;
    private const CONNECTION_SECONDS = 3600;
    private const TICKETS_PER_MINUTE = 12;
    private const CONNECTIONS_PER_SESSION = 12;

    public function __construct(
        private readonly PDO $database,
        private readonly Config $config,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function requireConfigured(): void
    {
        if (!$this->config->multiplayerV2IsConfigured()) {
            throw new ApiException(503, 'Multiplayer v2 is not configured.');
        }
    }

    public function authorizeService(HttpRequest $request): void
    {
        $this->requireConfigured();
        $header = $request->header('Authorization') ?? '';
        if (preg_match('/^Bearer ([\x21-\x7e]{32,512})$/D', $header, $matches) !== 1
            || !hash_equals(
                hash('sha256', $this->config->multiplayerServiceSecret ?? '', true),
                hash('sha256', $matches[1], true),
            )
        ) {
            throw new ApiException(401, 'Multiplayer service authentication failed.');
        }
    }

    public function issue(string $playerId, string $sessionHash, array $body): array
    {
        $this->requireConfigured();
        self::assertFields($body, ['protocolVersion', 'ruleset']);
        self::assertProtocol($body);
        if (!Uuid::isValidV4($playerId) || strlen($sessionHash) !== 32) {
            throw new ApiException(401, 'Sign in again to continue.');
        }
        return $this->transaction(function () use ($playerId, $sessionHash): array {
            $now = $this->now();
            $identity = $this->sessionIdentity($sessionHash, $playerId, $now, true);
            $this->prune($now, $sessionHash);
            $count = $this->database->prepare(
                'SELECT COUNT(*) FROM multiplayer_v2_tickets '
                . 'WHERE session_auth_hash = :session_hash AND created_at > :since'
            );
            $count->bindValue(':session_hash', $sessionHash, PDO::PARAM_LOB);
            $count->bindValue(':since', self::timestamp($now - self::TICKET_SECONDS));
            $count->execute();
            if ((int) $count->fetchColumn() >= self::TICKETS_PER_MINUTE) {
                throw new ApiException(429, 'Wait before requesting another connection.', ['Retry-After' => '60']);
            }
            $ticket = self::opaqueToken();
            $expires = min($now + self::TICKET_SECONDS, self::unixTime($identity['session_expires_at']));
            $insert = $this->database->prepare(
                'INSERT INTO multiplayer_v2_tickets '
                . '(ticket_hash, player_id, session_auth_hash, protocol_version, ruleset_id, created_at, expires_at) '
                . 'VALUES (:ticket_hash, :player_id, :session_hash, :protocol, :ruleset, :created, :expires)'
            );
            $insert->bindValue(':ticket_hash', self::tokenHash($ticket), PDO::PARAM_LOB);
            $insert->bindValue(':player_id', $playerId);
            $insert->bindValue(':session_hash', $sessionHash, PDO::PARAM_LOB);
            $insert->bindValue(':protocol', self::PROTOCOL_VERSION, PDO::PARAM_INT);
            $insert->bindValue(':ruleset', self::RULESET);
            $insert->bindValue(':created', self::timestamp($now));
            $insert->bindValue(':expires', self::timestamp($expires));
            $insert->execute();
            return ['ticket' => $ticket, 'expiresAt' => $expires, 'realtimeURL' => $this->config->realtimeUrl];
        });
    }

    public function redeem(array $body): array
    {
        $this->requireConfigured();
        self::assertFields($body, ['ticket', 'protocolVersion', 'ruleset']);
        self::assertProtocol($body);
        $hash = self::tokenHash($body['ticket'] ?? null);
        return $this->transaction(function () use ($hash): array {
            $now = $this->now();
            $select = $this->database->prepare('SELECT * FROM multiplayer_v2_tickets WHERE ticket_hash = :hash');
            $select->bindValue(':hash', $hash, PDO::PARAM_LOB);
            $select->execute();
            $ticket = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($ticket) || $ticket['consumed_at'] !== null
                || self::unixTime($ticket['expires_at']) <= $now
                || (int) $ticket['protocol_version'] !== self::PROTOCOL_VERSION
                || $ticket['ruleset_id'] !== self::RULESET
            ) {
                throw new ApiException(401, 'The connection ticket is invalid or expired.');
            }
            $identity = $this->sessionIdentity($ticket['session_auth_hash'], $ticket['player_id'], $now, true);
            // All issue/redeem operations lock the session before its credentials.
            // Re-read under that lock so two redemptions cannot consume one ticket.
            $select = $this->database->prepare(
                'SELECT * FROM multiplayer_v2_tickets WHERE ticket_hash = :hash' . $this->lockSuffix()
            );
            $select->bindValue(':hash', $hash, PDO::PARAM_LOB);
            $select->execute();
            $ticket = $select->fetch(PDO::FETCH_ASSOC);
            $now = $this->now();
            if (!is_array($ticket) || $ticket['consumed_at'] !== null
                || self::unixTime($ticket['expires_at']) <= $now
                || self::unixTime($identity['session_expires_at']) <= $now
            ) {
                throw new ApiException(401, 'The connection ticket is invalid or expired.');
            }
            $this->prune($now, $ticket['session_auth_hash']);
            $consume = $this->database->prepare(
                'UPDATE multiplayer_v2_tickets SET consumed_at = :now '
                . 'WHERE ticket_hash = :hash AND consumed_at IS NULL'
            );
            $consume->bindValue(':now', self::timestamp($now));
            $consume->bindValue(':hash', $hash, PDO::PARAM_LOB);
            $consume->execute();
            if ($consume->rowCount() !== 1) {
                throw new ApiException(401, 'The connection ticket is invalid or expired.');
            }
            $this->makeConnectionCapacity($ticket['session_auth_hash']);
            $binding = self::opaqueToken();
            $expires = min($now + self::CONNECTION_SECONDS, self::unixTime($identity['session_expires_at']));
            $insert = $this->database->prepare(
                'INSERT INTO multiplayer_v2_connections '
                . '(binding_hash, player_id, session_auth_hash, protocol_version, ruleset_id, created_at, expires_at) '
                . 'VALUES (:binding, :player, :session, :protocol, :ruleset, :created, :expires)'
            );
            $insert->bindValue(':binding', self::tokenHash($binding), PDO::PARAM_LOB);
            $insert->bindValue(':player', $ticket['player_id']);
            $insert->bindValue(':session', $ticket['session_auth_hash'], PDO::PARAM_LOB);
            $insert->bindValue(':protocol', self::PROTOCOL_VERSION, PDO::PARAM_INT);
            $insert->bindValue(':ruleset', self::RULESET);
            $insert->bindValue(':created', self::timestamp($now));
            $insert->bindValue(':expires', self::timestamp($expires));
            $insert->execute();
            return $this->identityPayload($identity, $binding, $expires);
        });
    }

    /** Called only after consuming a valid ticket under its parent session lock. */
    private function makeConnectionCapacity(string $sessionHash): void
    {
        // These are expiring credentials, not a count of live sockets. Reconnecting
        // must not exhaust a valid login for an hour. Prefer the newly authenticated
        // connection and revoke the oldest same-session binding when the cap is full.
        // A locking read also avoids a stale repeatable-read snapshot after waiting
        // for another redemption's session lock on MariaDB/MySQL.
        $select = $this->database->prepare(
            'SELECT binding_hash FROM multiplayer_v2_connections WHERE session_auth_hash = :session '
            . 'ORDER BY created_at ASC, binding_hash ASC' . $this->lockSuffix()
        );
        $select->bindValue(':session', $sessionHash, PDO::PARAM_LOB);
        $select->execute();
        $bindings = $select->fetchAll(PDO::FETCH_COLUMN);
        $removeCount = max(0, count($bindings) - self::CONNECTIONS_PER_SESSION + 1);
        if ($removeCount === 0) return;
        $delete = $this->database->prepare(
            'DELETE FROM multiplayer_v2_connections WHERE session_auth_hash = :session AND binding_hash = :binding'
        );
        $delete->bindValue(':session', $sessionHash, PDO::PARAM_LOB);
        foreach (array_slice($bindings, 0, $removeCount) as $binding) {
            $delete->bindValue(':binding', $binding, PDO::PARAM_LOB);
            $delete->execute();
        }
    }

    public function validateSession(array $body): array
    {
        $this->requireConfigured();
        self::assertFields($body, ['playerID', 'sessionBinding', 'protocolVersion', 'ruleset']);
        self::assertProtocol($body);
        $playerId = $body['playerID'] ?? null;
        if (!is_string($playerId) || !Uuid::isValidV4($playerId)) {
            throw new ApiException(401, 'The realtime session is invalid or expired.');
        }
        $binding = $body['sessionBinding'] ?? null;
        $hash = self::tokenHash($binding);
        $select = $this->database->prepare(
            'SELECT * FROM multiplayer_v2_connections WHERE binding_hash = :hash AND player_id = :player'
        );
        $select->bindValue(':hash', $hash, PDO::PARAM_LOB);
        $select->bindValue(':player', strtolower($playerId));
        $select->execute();
        $connection = $select->fetch(PDO::FETCH_ASSOC);
        $now = $this->now();
        if (!is_array($connection) || self::unixTime($connection['expires_at']) <= $now
            || (int) $connection['protocol_version'] !== self::PROTOCOL_VERSION
            || $connection['ruleset_id'] !== self::RULESET
        ) {
            throw new ApiException(401, 'The realtime session is invalid or expired.');
        }
        $identity = $this->sessionIdentity($connection['session_auth_hash'], $connection['player_id'], $now, false);
        return $this->identityPayload(
            $identity,
            $binding,
            min(self::unixTime($connection['expires_at']), self::unixTime($identity['session_expires_at'])),
        );
    }

    private function sessionIdentity(string $sessionHash, string $playerId, int $now, bool $lock): array
    {
        $select = $this->database->prepare(
            'SELECT player.id, player.nickname, player.nickname_confirmed, player.economy_generation, '
            . 'session.expires_at AS session_expires_at FROM player_sessions session '
            . 'JOIN players player ON player.id = session.player_id '
            . 'WHERE session.session_auth_hash = :hash AND session.player_id = :player '
            . 'AND session.expires_at > :now' . ($lock ? $this->lockSuffix() : '')
        );
        $select->bindValue(':hash', $sessionHash, PDO::PARAM_LOB);
        $select->bindValue(':player', $playerId);
        $select->bindValue(':now', self::timestamp($now));
        $select->execute();
        $identity = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($identity)) {
            throw new ApiException(401, 'The realtime session is invalid or expired.');
        }
        if (!(bool) $identity['nickname_confirmed']) {
            throw new ApiException(403, 'Confirm your player name before playing multiplayer.');
        }
        return $identity;
    }

    private function identityPayload(array $identity, string $binding, int $expires): array
    {
        $pet = $this->database->prepare(
            'SELECT pet_id FROM player_pet_selection WHERE player_id = :player AND is_visible = 1'
        );
        $pet->execute(['player' => $identity['id']]);
        $petId = $pet->fetchColumn();
        return [
            'playerID' => $identity['id'],
            'name' => $identity['nickname'],
            'petID' => is_string($petId) ? $petId : null,
            'sessionBinding' => $binding,
            'expiresAt' => $expires,
            'protocolVersion' => self::PROTOCOL_VERSION,
            'ruleset' => self::RULESET,
            // Trusted service snapshots this generation at match start. Never
            // refresh it mid-match or let queued pre-reset play earn after reset.
            'economyGeneration' => (int) $identity['economy_generation'],
        ];
    }

    private function prune(int $now, string $sessionHash): void
    {
        foreach (['multiplayer_v2_tickets', 'multiplayer_v2_connections'] as $table) {
            $delete = $this->database->prepare(
                'DELETE FROM ' . $table . ' WHERE session_auth_hash = :hash AND expires_at <= :now'
            );
            $delete->bindValue(':hash', $sessionHash, PDO::PARAM_LOB);
            $delete->bindValue(':now', self::timestamp($now));
            $delete->execute();
        }
    }

    private static function assertFields(array $body, array $fields): void
    {
        if (array_diff(array_keys($body), $fields) !== []) {
            throw new ApiException(400, 'Unexpected multiplayer v2 request fields.');
        }
    }

    private static function assertProtocol(array $body): void
    {
        if (($body['protocolVersion'] ?? null) !== self::PROTOCOL_VERSION
            || ($body['ruleset'] ?? null) !== self::RULESET
        ) {
            throw new ApiException(409, 'This multiplayer protocol is not supported.');
        }
    }

    private static function opaqueToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function tokenHash(mixed $token): string
    {
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1
            || self::base64RoundTrip($token) !== $token
        ) {
            throw new ApiException(401, 'The realtime credential is invalid or expired.');
        }
        return hash('sha256', $token, true);
    }

    private static function base64RoundTrip(string $token): string
    {
        $bytes = base64_decode(strtr($token, '-_', '+/') . '=', true);
        return is_string($bytes) && strlen($bytes) === 32
            ? rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') : '';
    }

    private function transaction(callable $operation): array
    {
        $this->database->beginTransaction();
        try {
            $result = $operation();
            $this->database->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function lockSuffix(): string
    {
        return $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    private static function timestamp(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }

    private static function unixTime(string $timestamp): int
    {
        return (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
