<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

/** Service-reported local alpha aggregates, deliberately excluded from ranked/economy state. */
final class MultiplayerV2ResultService
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function store(array $body): array
    {
        $payload = self::normalize($body);
        $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
        $this->database->beginTransaction();
        try {
            // Lock accounts in stable order so deletion cannot race a late outbox write.
            $ids = array_column($payload['players'], 'playerID');
            sort($ids, SORT_STRING);
            $exists = $this->database->prepare('SELECT id FROM players WHERE id = :id' . $this->lockSuffix());
            foreach ($ids as $id) {
                $exists->execute(['id' => $id]);
                if ($exists->fetchColumn() === false) {
                    throw new ApiException(409, 'A match participant no longer exists.');
                }
            }
            $existing = $this->existingDigest($payload['matchID'], true);
            if ($existing !== null) {
                $result = self::receipt($payload['matchID'], $digest, $existing);
                $this->database->commit();
                return $result;
            }
            $insert = $this->database->prepare(
                'INSERT INTO multiplayer_v2_results (match_id, payload_hash, protocol_version, ruleset_id, duration_ms, created_at) '
                . 'VALUES (:id, :hash, :protocol, :ruleset, :duration, :created)'
            );
            $insert->bindValue(':id', $payload['matchID']);
            $insert->bindValue(':hash', $digest, PDO::PARAM_LOB);
            $insert->bindValue(':protocol', MultiplayerV2Service::PROTOCOL_VERSION, PDO::PARAM_INT);
            $insert->bindValue(':ruleset', MultiplayerV2Service::RULESET);
            $insert->bindValue(':duration', $payload['durationMs'], PDO::PARAM_INT);
            $insert->bindValue(':created', gmdate('Y-m-d H:i:s'));
            $insert->execute();
            $participant = $this->database->prepare(
                'INSERT INTO multiplayer_v2_result_players '
                . '(match_id, player_id, seat, score, lives, hits, misses, dodges, reaction_total_ms, fastest_reaction_ms) '
                . 'VALUES (:match, :player, :seat, :score, :lives, :hits, :misses, :dodges, :reaction, :fastest)'
            );
            foreach ($payload['players'] as $player) {
                $participant->execute([
                    'match' => $payload['matchID'], 'player' => $player['playerID'], 'seat' => $player['seat'],
                    'score' => $player['score'], 'lives' => $player['lives'], 'hits' => $player['hits'],
                    'misses' => $player['misses'], 'dodges' => $player['dodges'],
                    'reaction' => $player['reactionTotalMs'], 'fastest' => $player['fastestReactionMs'],
                ]);
            }
            $this->database->commit();
            return self::receipt($payload['matchID'], $digest, null);
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            // Another service retry may have won the unique match-ID insert.
            if ($error instanceof PDOException && $error->getCode() === '23000') {
                $existing = $this->existingDigest($payload['matchID'], false);
                if ($existing !== null) return self::receipt($payload['matchID'], $digest, $existing);
            }
            throw $error;
        }
    }

    public function purgePlayerInCurrentTransaction(string $playerId): void
    {
        if (!$this->database->inTransaction()) {
            throw new \LogicException('V2 result deletion must share the account-deletion transaction.');
        }
        // These unranked alpha aggregates are not retained payment evidence.
        // Remove the shared match as a unit, including the digest and all seat rows.
        $delete = $this->database->prepare(
            'DELETE FROM multiplayer_v2_results WHERE match_id IN '
            . '(SELECT match_id FROM multiplayer_v2_result_players WHERE player_id = :player)'
        );
        $delete->execute(['player' => $playerId]);
    }

    private function existingDigest(string $matchId, bool $lock): ?string
    {
        $query = $this->database->prepare('SELECT payload_hash FROM multiplayer_v2_results WHERE match_id = :id'
            . ($lock ? $this->lockSuffix() : ''));
        $query->execute(['id' => $matchId]);
        $value = $query->fetchColumn();
        return is_string($value) ? $value : null;
    }

    private static function receipt(string $matchId, string $digest, ?string $existing): array
    {
        if ($existing !== null && !hash_equals($existing, $digest)) {
            throw new ApiException(409, 'This match already has a different immutable result.');
        }
        return ['matchID' => $matchId, 'duplicate' => $existing !== null,
            'rankingEligible' => false, 'state' => 'stored_unranked'];
    }

    private static function normalize(array $body): array
    {
        self::fields($body, ['matchID', 'protocolVersion', 'ruleset', 'durationMs', 'rankingEligible', 'players']);
        if (($body['protocolVersion'] ?? null) !== MultiplayerV2Service::PROTOCOL_VERSION
            || ($body['ruleset'] ?? null) !== MultiplayerV2Service::RULESET
        ) throw new ApiException(409, 'This multiplayer protocol is not supported.');
        if (($body['rankingEligible'] ?? null) !== false) {
            throw new ApiException(400, 'Multiplayer v2 alpha results cannot be ranked.');
        }
        $matchId = self::uuid($body['matchID'] ?? null);
        $duration = self::integer($body['durationMs'] ?? null, 900_000);
        $players = $body['players'] ?? null;
        if (!is_array($players) || !array_is_list($players) || count($players) < 2 || count($players) > 4) {
            throw new ApiException(400, 'A multiplayer result must contain 2 to 4 seats.');
        }
        $normalized = [];
        foreach ($players as $player) {
            if (!is_array($player)) throw new ApiException(400, 'Invalid result participant.');
            self::fields($player, ['playerID', 'seat', 'score', 'lives', 'hits', 'misses', 'dodges', 'reactionTotalMs', 'fastestReactionMs']);
            $fastest = $player['fastestReactionMs'] ?? null;
            $normalized[] = [
                'playerID' => self::uuid($player['playerID'] ?? null),
                'seat' => self::integer($player['seat'] ?? null, 3),
                'score' => self::integer($player['score'] ?? null, 100_000_000),
                'lives' => self::integer($player['lives'] ?? null, 3),
                'hits' => self::integer($player['hits'] ?? null, 100_000),
                'misses' => self::integer($player['misses'] ?? null, 3),
                'dodges' => self::integer($player['dodges'] ?? null, 100_000),
                'reactionTotalMs' => self::integer($player['reactionTotalMs'] ?? null, 100_000_000),
                'fastestReactionMs' => $fastest === null ? null : self::integer($fastest, 1000),
            ];
        }
        usort($normalized, static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
        if (array_column($normalized, 'seat') !== range(0, count($normalized) - 1)
            || count(array_unique(array_column($normalized, 'playerID'))) !== count($normalized)
        ) throw new ApiException(400, 'Result seats and players must be unique and contiguous.');
        return ['matchID' => $matchId, 'protocolVersion' => MultiplayerV2Service::PROTOCOL_VERSION,
            'ruleset' => MultiplayerV2Service::RULESET, 'durationMs' => $duration,
            'rankingEligible' => false, 'players' => $normalized];
    }

    private static function fields(array $body, array $allowed): void
    {
        if (array_diff(array_keys($body), $allowed) !== []) {
            throw new ApiException(400, 'Unexpected multiplayer result fields.');
        }
    }

    private static function uuid(mixed $value): string
    {
        if (!is_string($value) || !Uuid::isValidV4($value)) throw new ApiException(400, 'Invalid result identity.');
        return strtolower($value);
    }

    private static function integer(mixed $value, int $maximum): int
    {
        if (!is_int($value) || $value < 0 || $value > $maximum) throw new ApiException(400, 'Invalid result metric.');
        return $value;
    }

    private function lockSuffix(): string
    {
        return $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
