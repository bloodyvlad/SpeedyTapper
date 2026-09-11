<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;
use Throwable;

/** Legacy alpha storage plus explicitly versioned, trusted revision-3 settlement. */
final class MultiplayerV2ResultService
{
    public const RESULT_REVISION = 2;
    public const GAMEPLAY_REVISION = 3;
    public const REWARD_POLICY = 'multiplayer-alive-minute-v1';

    public function __construct(private readonly PDO $database,
        private readonly ?MultiplayerV2LeaderboardRepository $leaderboard = null)
    {
    }

    public function store(array $body): array
    {
        $payload = self::normalize($body);
        if ($payload['rankingEligible'] && $this->leaderboard === null) {
            throw new ApiException(503, 'The current multiplayer leaderboard is not configured.');
        }
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
                $result = $this->receipt($payload, $digest, $existing);
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
            if (isset($payload['resultRevision'])) {
                $rewards = new MultiplayerRewardService($this->database);
                foreach ($payload['players'] as $player) {
                    $rewards->creditInCurrentTransaction($payload, $digest, $player);
                }
                $this->leaderboard?->insertInCurrentTransaction($payload);
            }
            $result = $this->receipt($payload, $digest, null);
            $this->database->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            // Another service retry may have won the unique match-ID insert.
            if ($error instanceof PDOException && $error->getCode() === '23000') {
                $existing = $this->existingDigest($payload['matchID'], false);
                if ($existing !== null) return $this->receipt($payload, $digest, $existing);
            }
            throw $error;
        }
    }

    /** An authenticated owner can read only their own immutable settlement. */
    public function participantReceipt(string $matchId, string $playerId): array
    {
        // Use the same not-found result for invalid, absent and nonparticipant IDs.
        if (!Uuid::isValidV4($matchId)) throw new ApiException(404, 'Match settlement is not available.');
        $query = $this->database->prepare('SELECT match_id,match_kind,completion_reason,credited_alive_ms, '
            . 'coins_awarded,remainder_after_ms,total_alive_ms,coin_status FROM multiplayer_v2_reward_receipts '
            . 'WHERE match_id = :match AND player_id = :player');
        $query->execute(['match' => strtolower($matchId), 'player' => $playerId]);
        $row = $query->fetch();
        if (!is_array($row)) throw new ApiException(404, 'Match settlement is not available.');
        $ranked = $row['match_kind'] === 'competitive' && $row['completion_reason'] === 'completed';
        return ['matchID' => $row['match_id'], 'state' => $ranked ? 'stored_ranked' : 'stored_unranked',
            'rankingEligible' => $ranked, 'resultRevision' => self::RESULT_REVISION,
            'rewardPolicy' => self::REWARD_POLICY,
            'reward' => ['creditedAliveMs' => (int) $row['credited_alive_ms'],
                'coinsEarned' => (int) $row['coins_awarded'], 'remainderMs' => (int) $row['remainder_after_ms'],
                'totalAliveMs' => (int) $row['total_alive_ms'], 'coinStatus' => $row['coin_status']]];
    }

    public function purgePlayerInCurrentTransaction(string $playerId): void
    {
        if (!$this->database->inTransaction()) {
            throw new \LogicException('V2 result deletion must share the account-deletion transaction.');
        }
        // Remove shared alpha history. New per-owner rewards/rankings deliberately
        // have no match FK, preserving other players' independent value/evidence.
        // The deleted owner's rows cascade when their account is removed.
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

    private function receipt(array $payload, string $digest, ?string $existing): array
    {
        if ($existing !== null && !hash_equals($existing, $digest)) {
            throw new ApiException(409, 'This match already has a different immutable result.');
        }
        $receipt = ['matchID' => $payload['matchID'], 'duplicate' => $existing !== null,
            'rankingEligible' => $payload['rankingEligible'],
            'state' => $payload['rankingEligible'] ? 'stored_ranked' : 'stored_unranked'];
        if (isset($payload['resultRevision'])) {
            $rewards = (new MultiplayerRewardService($this->database))->receipts($payload['matchID']);
            if (count($rewards) !== count($payload['players'])) {
                throw new ApiException(409, 'The immutable multiplayer reward receipt is unavailable.');
            }
            $receipt += ['resultRevision' => self::RESULT_REVISION, 'rewardPolicy' => self::REWARD_POLICY,
                'rewards' => $rewards];
        }
        return $receipt;
    }

    private static function normalize(array $body): array
    {
        $versioned = array_key_exists('resultRevision', $body);
        self::fields($body, ['matchID', 'protocolVersion', 'ruleset', 'durationMs', 'rankingEligible', 'players',
            ...($versioned ? ['resultRevision', 'gameplayRevision', 'rewardPolicy', 'matchKind', 'completionReason'] : [])]);
        if (($body['protocolVersion'] ?? null) !== MultiplayerV2Service::PROTOCOL_VERSION
            || ($body['ruleset'] ?? null) !== MultiplayerV2Service::RULESET
        ) throw new ApiException(409, 'This multiplayer protocol is not supported.');
        $rankingEligible = false;
        if ($versioned) {
            if (($body['resultRevision'] ?? null) !== self::RESULT_REVISION
                || ($body['gameplayRevision'] ?? null) !== self::GAMEPLAY_REVISION
                || ($body['rewardPolicy'] ?? null) !== self::REWARD_POLICY) {
                throw new ApiException(409, 'This multiplayer result and reward revision is not supported.');
            }
            if (!in_array($body['matchKind'] ?? null, ['competitive', 'tutorial'], true)
                || !in_array($body['completionReason'] ?? null, ['completed', 'aborted'], true)) {
                throw new ApiException(400, 'Invalid multiplayer completion context.');
            }
            $rankingEligible = $body['matchKind'] === 'competitive' && $body['completionReason'] === 'completed';
        }
        if (($body['rankingEligible'] ?? null) !== $rankingEligible) {
            throw new ApiException(400, 'Multiplayer ranking eligibility does not match its result contract.');
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
            self::fields($player, ['playerID', 'seat', 'score', 'lives', 'hits', 'misses', 'dodges', 'reactionTotalMs', 'fastestReactionMs',
                ...($versioned ? ['eligibleAliveMs', 'economyGeneration', 'survivalMs', 'maxMultiplier'] : [])]);
            $fastest = $player['fastestReactionMs'] ?? null;
            $row = [
                'playerID' => self::uuid($player['playerID'] ?? null),
                'seat' => self::integer($player['seat'] ?? null, 3),
                'score' => self::integer($player['score'] ?? null, 100_000_000),
                'lives' => self::integer($player['lives'] ?? null, 3),
                'hits' => self::integer($player['hits'] ?? null, 100_000),
                // Heart pickups can restore a life; mistakes remain cumulative.
                'misses' => self::integer($player['misses'] ?? null, 1000),
                'dodges' => self::integer($player['dodges'] ?? null, 100_000),
                'reactionTotalMs' => self::integer($player['reactionTotalMs'] ?? null, 100_000_000),
                'fastestReactionMs' => $fastest === null ? null : self::integer($fastest, 1000),
            ];
            if ($versioned) {
                $survival = self::integer($player['survivalMs'] ?? null, $duration);
                $maximum = self::integer($player['maxMultiplier'] ?? null, 5);
                if ($maximum < 1) throw new ApiException(400, 'Invalid multiplayer peak multiplier.');
                $generation = $player['economyGeneration'] ?? null;
                $row += ['eligibleAliveMs' => self::integer($player['eligibleAliveMs'] ?? null, $survival),
                    'economyGeneration' => $generation === null ? null : self::integer($generation, 4_294_967_295),
                    'survivalMs' => $survival, 'maxMultiplier' => $maximum];
            }
            $normalized[] = $row;
        }
        usort($normalized, static fn (array $a, array $b): int => $a['seat'] <=> $b['seat']);
        if (array_column($normalized, 'seat') !== range(0, count($normalized) - 1)
            || count(array_unique(array_column($normalized, 'playerID'))) !== count($normalized)
        ) throw new ApiException(400, 'Result seats and players must be unique and contiguous.');
        $payload = ['matchID' => $matchId, 'protocolVersion' => MultiplayerV2Service::PROTOCOL_VERSION,
            'ruleset' => MultiplayerV2Service::RULESET, 'durationMs' => $duration,
            'rankingEligible' => $rankingEligible, 'players' => $normalized];
        if ($versioned) $payload += ['resultRevision' => self::RESULT_REVISION, 'gameplayRevision' => self::GAMEPLAY_REVISION,
            'rewardPolicy' => self::REWARD_POLICY, 'matchKind' => $body['matchKind'], 'completionReason' => $body['completionReason']];
        return $payload;
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
