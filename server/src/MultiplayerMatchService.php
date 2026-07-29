<?php

declare(strict_types=1);

namespace SpeedyTapper;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/**
 * Authenticated coordination and final-proof settlement for 2–4 player
 * GameKit matches. Live taps and HUD updates remain peer-to-peer in GKMatch;
 * this service is deliberately absent from the reaction-critical path.
 */
final class MultiplayerMatchService
{
    private const LOBBY_LIFETIME_SECONDS = 600;
    private const GAME_CENTER_FRESHNESS_SECONDS = 600;
    private const SUBMISSION_GRACE_SECONDS = 300;
    private const CREATE_LIMIT_PER_TEN_MINUTES = 5;
    private const SUBMISSION_LIMIT_PER_HOUR = 20;
    private const SERVER_CLOCK_TOLERANCE_MS = 1_000;

    public function __construct(
        private readonly PDO $database,
        private readonly string $seasonId,
        private readonly string $seasonName,
        private readonly MultiplayerProofValidator $validator,
        private readonly ?GameCenterPublicationRepository $gameCenterPublication = null,
    ) {
    }

    public function listLobbies(string $playerId, int $limit = 20): array
    {
        $this->assertEligiblePlayer($playerId, false);
        $limit = max(1, min(50, $limit));
        $this->expireStaleLobbies();
        $statement = $this->database->prepare(
            'SELECT match_row.id, match_row.mode, match_row.capacity, match_row.created_at, '
            . 'match_row.expires_at, COUNT(participant.player_id) AS player_count, '
            . 'creator.nickname AS host_name, creator.nickname_confirmed AS host_confirmed, '
            . 'selection.pet_id AS host_pet_id, selection.is_visible AS host_pet_visible '
            . 'FROM multiplayer_matches match_row '
            . 'JOIN multiplayer_participants participant ON participant.match_id = match_row.id '
            . 'LEFT JOIN players creator ON creator.id = match_row.created_by_player_id '
            . 'LEFT JOIN player_pet_selection selection ON selection.player_id = creator.id '
            . "WHERE match_row.state = 'forming' AND match_row.expires_at > :now "
            . 'GROUP BY match_row.id, match_row.mode, match_row.capacity, match_row.created_at, '
            . 'match_row.expires_at, creator.nickname, creator.nickname_confirmed, '
            . 'selection.pet_id, selection.is_visible '
            . 'HAVING COUNT(participant.player_id) < match_row.capacity '
            . 'ORDER BY match_row.created_at ASC, match_row.id ASC LIMIT ' . $limit
        );
        $statement->execute(['now' => self::timestamp()]);
        $lobbies = [];
        foreach ($statement->fetchAll() as $row) {
            $lobbies[] = [
                'matchId' => (string) $row['id'],
                'mode' => (string) $row['mode'],
                'capacity' => (int) $row['capacity'],
                'playerCount' => (int) $row['player_count'],
                'host' => [
                    'name' => (string) ($row['host_name'] ?? ''),
                    'petId' => $this->visiblePet(
                        $row['host_name'] ?? null,
                        (bool) ($row['host_confirmed'] ?? false),
                        $row['host_pet_id'] ?? null,
                        (bool) ($row['host_pet_visible'] ?? false),
                    ),
                ],
                'createdAt' => self::isoDate((string) $row['created_at']),
                'expiresAt' => self::isoDate((string) $row['expires_at']),
            ];
        }
        return ['lobbies' => $lobbies];
    }

    public function create(
        string $playerId,
        mixed $mode,
        mixed $capacity,
        mixed $buildId,
    ): array {
        $this->assertEligiblePlayer($playerId, true);
        if ($mode !== MultiplayerCatalog::MODE_OWN_COLOR) {
            throw new ApiException(400, 'Multiplayer mode must be own_color.');
        }
        if (
            !is_int($capacity)
            || $capacity < MultiplayerCatalog::MIN_PLAYERS
            || $capacity > MultiplayerCatalog::MAX_PLAYERS
        ) {
            throw new ApiException(400, 'Multiplayer capacity must be between 2 and 4.');
        }
        if (!MultiplayerCatalog::supportsBuildId($buildId)) {
            throw new ApiException(409, 'Refresh before creating a multiplayer match.');
        }
        $matchId = Uuid::v4();
        $participantId = Uuid::v4();
        $now = self::now();
        $expiresAt = $now->modify('+' . self::LOBBY_LIFETIME_SECONDS . ' seconds');

        return $this->transaction(function () use (
            $playerId,
            $mode,
            $capacity,
            $buildId,
            $matchId,
            $participantId,
            $now,
            $expiresAt,
        ): array {
            $this->lockCreateRatePlayer($playerId);
            $this->enforceCreateRateLimit($playerId, $now);
            $playerGroup = $this->uniquePlayerGroup();
            $insert = $this->database->prepare(
                'INSERT INTO multiplayer_matches '
                . '(id, season_id, created_by_player_id, mode, state, capacity, player_group, '
                . 'build_id, ruleset_id, protocol_version, proof_version, seed, created_at, '
                . 'updated_at, expires_at) VALUES '
                . "(:id, :season_id, :player_id, :mode, 'forming', :capacity, :player_group, "
                . ':build_id, :ruleset_id, :protocol_version, :proof_version, :seed, :created_at, '
                . ':updated_at, :expires_at)'
            );
            $insert->execute([
                'id' => $matchId,
                'season_id' => $this->seasonId,
                'player_id' => $playerId,
                'mode' => $mode,
                'capacity' => $capacity,
                'player_group' => $playerGroup,
                'build_id' => $buildId,
                'ruleset_id' => MultiplayerCatalog::RULESET_ID,
                'protocol_version' => MultiplayerCatalog::PROTOCOL_VERSION,
                'proof_version' => MultiplayerCatalog::PROOF_VERSION,
                'seed' => random_bytes(32),
                'created_at' => self::timestamp($now),
                'updated_at' => self::timestamp($now),
                'expires_at' => self::timestamp($expiresAt),
            ]);
            $this->recordLobbyCreation($matchId, $playerId, $now);
            $this->insertParticipant($matchId, $participantId, $playerId, 0);
            return ['match' => $this->matchPayload($matchId, $playerId, true)];
        });
    }

    public function join(string $playerId, string $matchId): array
    {
        $this->assertEligiblePlayer($playerId, true);
        $matchId = self::matchId($matchId);
        $this->expireFormingMatchIfStale($matchId);
        return $this->transaction(function () use ($playerId, $matchId): array {
            $match = $this->lockMatch($matchId);
            $this->assertForming($match);
            $existing = $this->participant($matchId, $playerId, true);
            if ($existing === null) {
                $participants = $this->participantRows($matchId, true);
                if (count($participants) >= (int) $match['capacity']) {
                    throw new ApiException(409, 'This multiplayer lobby is full.');
                }
                $seat = $this->lowestFreeSeat($participants);
                $this->insertParticipant(
                    $matchId,
                    Uuid::v4(),
                    $playerId,
                    $seat,
                );
                $this->database->prepare(
                    'DELETE FROM multiplayer_roster_confirmations WHERE match_id = :match_id'
                )->execute(['match_id' => $matchId]);
            }
            return ['match' => $this->matchPayload($matchId, $playerId, true)];
        });
    }

    public function leave(string $playerId, string $matchId): array
    {
        $matchId = self::matchId($matchId);
        return $this->transaction(function () use ($playerId, $matchId): array {
            $match = $this->lockMatch($matchId);
            $member = $this->requireMember($matchId, $playerId, true);
            $state = (string) $match['state'];
            if (in_array($state, ['active', 'collecting'], true)) {
                $this->cancelMatch(
                    $matchId,
                    'A participant left after the match started.',
                );
                return ['left' => true, 'matchCancelled' => true];
            }
            if ($state !== 'forming') {
                // Dismissing a finished or already-closed match must not mutate
                // immutable results or reopen its settlement state.
                return [
                    'left' => true,
                    'matchCancelled' => $state === 'cancelled',
                ];
            }
            $delete = $this->database->prepare(
                'DELETE FROM multiplayer_participants '
                . 'WHERE match_id = :match_id AND player_id = :player_id'
            );
            $delete->execute(['match_id' => $matchId, 'player_id' => $playerId]);
            $remaining = $this->participantRows($matchId, true);
            if ($remaining === []) {
                $this->cancelMatch($matchId, null);
                return ['left' => true, 'matchCancelled' => true];
            }
            $this->compactSeats($matchId, $remaining);
            if (hash_equals((string) ($match['created_by_player_id'] ?? ''), $playerId)) {
                $newCreator = $this->participantRows($matchId, true)[0]['player_id'];
                $update = $this->database->prepare(
                    'UPDATE multiplayer_matches SET created_by_player_id = :player_id, '
                    . 'updated_at = :updated_at WHERE id = :match_id'
                );
                $update->execute([
                    'player_id' => $newCreator,
                    'updated_at' => self::timestamp(),
                    'match_id' => $matchId,
                ]);
            }
            return ['left' => true, 'matchCancelled' => false];
        });
    }

    public function setReady(string $playerId, string $matchId, mixed $ready): array
    {
        if (!is_bool($ready)) {
            throw new ApiException(400, 'Multiplayer readiness must be true or false.');
        }
        $matchId = self::matchId($matchId);
        $this->requireMember($matchId, $playerId, false);
        $this->expireFormingMatchIfStale($matchId);
        return $this->transaction(function () use ($playerId, $matchId, $ready): array {
            $match = $this->lockMatch($matchId);
            $this->assertForming($match);
            $this->requireMember($matchId, $playerId, true);
            $statement = $this->database->prepare(
                'UPDATE multiplayer_participants SET ready = :ready, status = :status, '
                . 'updated_at = :updated_at WHERE match_id = :match_id AND player_id = :player_id'
            );
            $statement->execute([
                'ready' => $ready ? 1 : 0,
                'status' => $ready ? 'ready' : 'joined',
                'updated_at' => self::timestamp(),
                'match_id' => $matchId,
                'player_id' => $playerId,
            ]);
            return ['match' => $this->matchPayload($matchId, $playerId, true)];
        });
    }

    public function confirmGameKitRoster(
        string $playerId,
        string $matchId,
        mixed $localGamePlayerId,
        mixed $observedGamePlayerIds,
        mixed $coordinatorGamePlayerId,
    ): array {
        $this->assertEligiblePlayer($playerId, true);
        $matchId = self::matchId($matchId);
        $local = self::gamePlayerId($localGamePlayerId);
        $coordinator = self::gamePlayerId($coordinatorGamePlayerId);
        if (!is_array($observedGamePlayerIds) || !array_is_list($observedGamePlayerIds)) {
            throw new ApiException(400, 'The GameKit roster is invalid.');
        }
        $rawRoster = [$local];
        foreach ($observedGamePlayerIds as $rawId) {
            $rawRoster[] = self::gamePlayerId($rawId);
        }
        if (count($rawRoster) < 2 || count($rawRoster) > 4 || count(array_unique($rawRoster)) !== count($rawRoster)) {
            throw new ApiException(400, 'The GameKit roster must contain 2 to 4 unique players.');
        }
        if (!in_array($coordinator, $rawRoster, true)) {
            throw new ApiException(400, 'The coordinator is not in the GameKit roster.');
        }
        $this->requireMember($matchId, $playerId, false);
        $this->expireFormingMatchIfStale($matchId);

        return $this->transaction(function () use (
            $playerId,
            $matchId,
            $local,
            $coordinator,
            $rawRoster,
        ): array {
            $match = $this->lockMatch($matchId);
            $this->assertForming($match);
            $this->requireMember($matchId, $playerId, true);
            $participants = $this->participantRows($matchId, true);
            if (count($participants) !== count($rawRoster)) {
                throw new ApiException(409, 'The GameKit roster does not match the PHP lobby.');
            }
            $bindings = $this->gameCenterBindings(array_column($participants, 'player_id'));
            $localHash = self::gamePlayerIdHash($local);
            if (
                !isset($bindings[$playerId])
                || !hash_equals($bindings[$playerId], $localHash)
            ) {
                throw new ApiException(409, 'The local Game Center player does not match this profile.');
            }
            $submittedHashes = array_map(
                static fn (string $raw): string => self::gamePlayerIdHash($raw),
                $rawRoster,
            );
            $expectedHashes = array_values($bindings);
            usort($submittedHashes, 'strcmp');
            usort($expectedHashes, 'strcmp');
            if (
                count($expectedHashes) !== count($submittedHashes)
                || !$this->constantTimeHashListEquals($expectedHashes, $submittedHashes)
            ) {
                throw new ApiException(409, 'The GameKit roster does not match the PHP lobby.');
            }
            $coordinatorHash = self::gamePlayerIdHash($coordinator);
            $coordinatorPlayerId = array_search($coordinatorHash, $bindings, true);
            if (!is_string($coordinatorPlayerId)) {
                throw new ApiException(409, 'The GameKit coordinator is not a lobby member.');
            }
            $coordinatorParticipant = null;
            foreach ($participants as $participant) {
                if (hash_equals((string) $participant['player_id'], $coordinatorPlayerId)) {
                    $coordinatorParticipant = (string) $participant['participant_id'];
                    break;
                }
            }
            if ($coordinatorParticipant === null) {
                throw new LogicException('The coordinator participant mapping is missing.');
            }
            $rosterHash = hash('sha256', implode('', $submittedHashes), true);
            $this->upsertRosterConfirmation(
                $matchId,
                $playerId,
                $rosterHash,
                $coordinatorParticipant,
            );
            return [
                'confirmed' => true,
                'confirmedCount' => $this->confirmationCount($matchId),
                'participantCount' => count($participants),
            ];
        });
    }

    public function start(string $playerId, string $matchId): array
    {
        $this->assertEligiblePlayer($playerId, true);
        $matchId = self::matchId($matchId);
        $this->requireMember($matchId, $playerId, false);
        $this->expireFormingMatchIfStale($matchId);
        return $this->transaction(function () use ($playerId, $matchId): array {
            $match = $this->lockMatch($matchId);
            $this->assertForming($match);
            if (!hash_equals((string) ($match['created_by_player_id'] ?? ''), $playerId)) {
                throw new ApiException(403, 'Only the lobby creator can start this match.');
            }
            $participants = $this->participantRows($matchId, true);
            if (
                count($participants) < MultiplayerCatalog::MIN_PLAYERS
                || count($participants) > MultiplayerCatalog::MAX_PLAYERS
            ) {
                throw new ApiException(409, 'A multiplayer match needs 2 to 4 players.');
            }
            foreach ($participants as $participant) {
                $this->assertEligiblePlayer((string) $participant['player_id'], true);
                if ((int) $participant['ready'] !== 1) {
                    throw new ApiException(409, 'Every multiplayer participant must be ready.');
                }
            }
            $confirmations = $this->rosterConfirmations($matchId, true);
            if (count($confirmations) !== count($participants)) {
                throw new ApiException(409, 'Every participant must confirm the live GameKit roster.');
            }
            $rosterHash = $confirmations[0]['roster_hash'];
            $coordinator = (string) $confirmations[0]['coordinator_participant_id'];
            foreach ($confirmations as $confirmation) {
                if (
                    !hash_equals($rosterHash, (string) $confirmation['roster_hash'])
                    || !hash_equals($coordinator, (string) $confirmation['coordinator_participant_id'])
                ) {
                    throw new ApiException(409, 'GameKit roster confirmations do not agree.');
                }
            }
            $manifest = $this->manifest($match, $participants);
            $manifestHash = hash('sha256', self::canonicalJson($manifest), true);
            $now = self::now();
            $deadline = $now->modify(
                '+' . (int) ceil(MultiplayerCatalog::MAX_DURATION_MS / 1_000)
                . ' seconds +' . self::SUBMISSION_GRACE_SECONDS . ' seconds',
            );
            $update = $this->database->prepare(
                "UPDATE multiplayer_matches SET state = 'active', manifest_hash = :manifest_hash, "
                . 'roster_hash = :roster_hash, coordinator_participant_id = :coordinator, '
                . 'started_at = :started_at, submission_deadline_at = :deadline, '
                . 'updated_at = :updated_at WHERE id = :match_id'
            );
            $update->execute([
                'manifest_hash' => $manifestHash,
                'roster_hash' => $rosterHash,
                'coordinator' => $coordinator,
                'started_at' => self::timestamp($now),
                'deadline' => self::timestamp($deadline),
                'updated_at' => self::timestamp($now),
                'match_id' => $matchId,
            ]);
            $activate = $this->database->prepare(
                "UPDATE multiplayer_participants SET status = 'active', updated_at = :updated_at "
                . 'WHERE match_id = :match_id'
            );
            $activate->execute([
                'updated_at' => self::timestamp($now),
                'match_id' => $matchId,
            ]);
            return [
                'manifest' => [
                    ...$manifest,
                    'manifestHash' => self::base64UrlEncode($manifestHash),
                ],
                'participants' => $this->publicParticipants($matchId, $playerId),
            ];
        });
    }

    public function show(string $playerId, string $matchId): array
    {
        $matchId = self::matchId($matchId);
        $this->requireMember($matchId, $playerId, false);
        $this->expireFormingMatchIfStale($matchId);
        return ['match' => $this->matchPayload($matchId, $playerId, true)];
    }

    public function submit(
        string $playerId,
        string $matchId,
        mixed $manifestHashValue,
        mixed $transcriptValue,
    ): array {
        $matchId = self::matchId($matchId);
        $manifestHash = self::base64UrlDecode32($manifestHashValue, 'manifest hash');
        if (!is_array($transcriptValue)) {
            throw new ApiException(400, 'Multiplayer transcript is required.');
        }
        $transcript = MultiplayerTranscript::fromArray($transcriptValue);
        if (!hash_equals($matchId, $transcript->matchId)) {
            throw new ApiException(400, 'Multiplayer transcript belongs to another match.');
        }

        return $this->transaction(function () use (
            $playerId,
            $matchId,
            $manifestHash,
            $transcript,
        ): array {
            $match = $this->lockMatch($matchId);
            $member = $this->requireMember($matchId, $playerId, true);
            if (!in_array((string) $match['state'], ['active', 'collecting'], true)) {
                if (in_array((string) $match['state'], ['settled', 'review'], true)) {
                    return $this->settlementPayload($matchId, $playerId);
                }
                throw new ApiException(409, 'This multiplayer match does not accept results.');
            }
            if (
                !is_string($match['manifest_hash'])
                || !hash_equals($match['manifest_hash'], $manifestHash)
            ) {
                throw new ApiException(409, 'The multiplayer manifest does not match.');
            }
            if (
                !hash_equals((string) $match['build_id'], $transcript->buildId)
                || !hash_equals((string) $match['ruleset_id'], $transcript->ruleset)
                || (int) $match['protocol_version'] !== $transcript->protocolVersion
                || (int) $match['proof_version'] !== $transcript->proofVersion
            ) {
                throw new ApiException(409, 'The multiplayer transcript contract does not match.');
            }
            $hash = $transcript->hash();
            $existing = $this->submission($matchId, $playerId, true);
            if ($existing !== null) {
                if (
                    hash_equals((string) $existing['manifest_hash'], $manifestHash)
                    && hash_equals((string) $existing['transcript_hash'], $hash)
                ) {
                    return [
                        'duplicate' => true,
                        ...$this->settlementPayload($matchId, $playerId),
                    ];
                }
                $this->markMatch(
                    $matchId,
                    'review',
                    'A participant submitted conflicting match evidence.',
                );
                return [
                    'duplicate' => false,
                    'conflict' => true,
                    ...$this->settlementPayload($matchId, $playerId),
                ];
            }
            if (
                !is_string($match['submission_deadline_at'])
                || self::parseDate($match['submission_deadline_at']) <= self::now()
            ) {
                $this->markMatch(
                    $matchId,
                    'review',
                    'The multiplayer submission deadline passed.',
                );
                return [
                    'duplicate' => false,
                    ...$this->settlementPayload($matchId, $playerId),
                ];
            }
            // Exact retries are idempotent even after the player's hourly
            // insertion budget is exhausted. Only a genuinely new stored
            // submission consumes that budget.
            $this->enforceSubmissionRateLimit($playerId);
            $insert = $this->database->prepare(
                'INSERT INTO multiplayer_submissions '
                . '(id, match_id, player_id, manifest_hash, transcript_hash, event_count, '
                . 'proof_json, submitted_at) VALUES '
                . '(:id, :match_id, :player_id, :manifest_hash, :transcript_hash, '
                . ':event_count, :proof_json, :submitted_at)'
            );
            $insert->execute([
                'id' => Uuid::v4(),
                'match_id' => $matchId,
                'player_id' => $playerId,
                'manifest_hash' => $manifestHash,
                'transcript_hash' => $hash,
                'event_count' => $transcript->eventCount(),
                'proof_json' => $transcript->canonicalJson(),
                'submitted_at' => self::timestamp(),
            ]);
            $updateParticipant = $this->database->prepare(
                "UPDATE multiplayer_participants SET status = 'submitted', updated_at = :updated_at "
                . 'WHERE match_id = :match_id AND player_id = :player_id'
            );
            $updateParticipant->execute([
                'updated_at' => self::timestamp(),
                'match_id' => $matchId,
                'player_id' => $playerId,
            ]);
            $participants = $this->participantRows($matchId, true);
            $submissions = $this->submissions($matchId, true);
            if (count($submissions) < count($participants)) {
                $this->setCollecting($matchId);
                return [
                    'duplicate' => false,
                    'state' => 'collecting',
                    'submittedCount' => count($submissions),
                    'participantCount' => count($participants),
                    'leaderboardEligible' => false,
                ];
            }
            $firstHash = (string) $submissions[0]['transcript_hash'];
            foreach ($submissions as $submission) {
                if (
                    !hash_equals($firstHash, (string) $submission['transcript_hash'])
                    || !hash_equals($manifestHash, (string) $submission['manifest_hash'])
                ) {
                    $this->markMatch(
                        $matchId,
                        'review',
                        'Participants did not submit matching evidence.',
                    );
                    return $this->settlementPayload($matchId, $playerId);
                }
            }

            $duplicateTrace = $this->claimTrace($matchId, $transcript->traceHash());
            try {
                $verified = $this->validator->validate($transcript, array_map(
                    static fn (array $participant): array => [
                        'participantId' => (string) $participant['participant_id'],
                        'playerId' => (string) $participant['player_id'],
                        'seat' => (int) $participant['seat'],
                        'colorIndex' => (int) $participant['color_index'],
                    ],
                    $participants,
                ));
            } catch (ApiException $error) {
                $this->markMatch(
                    $matchId,
                    'review',
                    'Server replay rejected the shared transcript.',
                );
                return $this->settlementPayload($matchId, $playerId);
            }
            $serverElapsed = $this->elapsedMilliseconds((string) $match['started_at']);
            if ($serverElapsed + self::SERVER_CLOCK_TOLERANCE_MS < $verified['durationMs']) {
                $this->markMatch($matchId, 'review', 'The match timeline compressed server time.');
                return $this->settlementPayload($matchId, $playerId);
            }
            $status = $duplicateTrace
                ? 'quarantined'
                : ($verified['riskScore'] >= 100 ? 'review' : 'verified');
            $this->insertResults($matchId, $verified['results'], $status, $verified);
            $this->settleMatch($matchId, $status, $verified);
            if ($status === 'verified') {
                $publicationParticipants = $participants;
                usort(
                    $publicationParticipants,
                    static fn (array $left, array $right): int =>
                        strcmp((string) $left['player_id'], (string) $right['player_id']),
                );
                foreach ($publicationParticipants as $participant) {
                    $this->gameCenterPublication
                        ?->enqueueBestMultiplayerScoreInCurrentTransaction(
                            (string) $participant['player_id'],
                        );
                }
            }
            return [
                'duplicate' => false,
                ...$this->settlementPayload($matchId, $playerId),
            ];
        });
    }

    public function settlement(string $playerId, string $matchId): array
    {
        $matchId = self::matchId($matchId);
        $this->requireMember($matchId, $playerId, false);
        return $this->settlementPayload($matchId, $playerId);
    }

    private function settlementPayload(string $matchId, string $playerId): array
    {
        $match = $this->match($matchId, false);
        $results = [];
        $statement = $this->database->prepare(
            'SELECT result_row.id, result_row.participant_id, result_row.placement, '
            . 'result_row.player_count, result_row.score, result_row.duration_ms, '
            . 'result_row.fastest_reaction_ms, result_row.average_reaction_ms, '
            . 'result_row.correct_taps, result_row.miss_count, result_row.dodge_count, '
            . 'result_row.godlike_count, result_row.perfect_count, result_row.great_count, '
            . 'result_row.good_count, result_row.max_multiplier, result_row.player_id, '
            . 'player.nickname, player.nickname_confirmed, selection.pet_id, selection.is_visible '
            . 'FROM multiplayer_results result_row '
            . 'JOIN players player ON player.id = result_row.player_id '
            . 'LEFT JOIN player_pet_selection selection ON selection.player_id = player.id '
            . 'WHERE result_row.match_id = :match_id '
            . 'ORDER BY result_row.placement ASC, result_row.id ASC'
        );
        $statement->execute(['match_id' => $matchId]);
        foreach ($statement->fetchAll() as $row) {
            $results[] = [
                'resultId' => (string) $row['id'],
                'participantId' => (string) $row['participant_id'],
                'place' => (int) $row['placement'],
                'playerCount' => (int) $row['player_count'],
                'name' => (string) $row['nickname'],
                'petId' => $this->visiblePet(
                    $row['nickname'],
                    (bool) $row['nickname_confirmed'],
                    $row['pet_id'] ?? null,
                    (bool) ($row['is_visible'] ?? false),
                ),
                'score' => (int) $row['score'],
                'survivalMs' => (int) $row['duration_ms'],
                'hits' => (int) $row['correct_taps'],
                'misses' => (int) $row['miss_count'],
                'dodges' => (int) $row['dodge_count'],
                'fastestReactionMs' => $row['fastest_reaction_ms'] === null
                    ? null : (int) $row['fastest_reaction_ms'],
                'averageReactionMs' => $row['average_reaction_ms'] === null
                    ? null : (int) $row['average_reaction_ms'],
                'maxMultiplier' => (int) $row['max_multiplier'],
                'speedRatings' => [
                    'godlike' => (int) $row['godlike_count'],
                    'perfect' => (int) $row['perfect_count'],
                    'great' => (int) $row['great_count'],
                    'good' => (int) $row['good_count'],
                ],
                'isCurrentPlayer' => hash_equals((string) $row['player_id'], $playerId),
            ];
        }
        $state = (string) $match['state'];
        return [
            'state' => $state,
            'leaderboardEligible' => $state === 'settled',
            'verification' => $state === 'settled' ? 'peer_consistent_v1' : null,
            'reviewReason' => $state === 'review' ? ($match['review_reason'] ?? null) : null,
            'results' => $results,
        ];
    }

    private function matchPayload(string $matchId, string $playerId, bool $private): array
    {
        $match = $this->match($matchId, false);
        $member = $this->requireMember($matchId, $playerId, false);
        $payload = [
            'matchId' => (string) $match['id'],
            'state' => (string) $match['state'],
            'mode' => (string) $match['mode'],
            'capacity' => (int) $match['capacity'],
            'selfParticipantId' => (string) $member['participant_id'],
            'isCreator' => hash_equals(
                (string) ($match['created_by_player_id'] ?? ''),
                $playerId,
            ),
            'participants' => $this->publicParticipants($matchId, $playerId),
            'expiresAt' => self::isoDate((string) $match['expires_at']),
        ];
        if ($private) {
            $payload['playerGroup'] = (int) $match['player_group'];
        }
        if (
            in_array((string) $match['state'], ['active', 'collecting'], true)
            && is_string($match['manifest_hash'] ?? null)
        ) {
            $payload['manifest'] = [
                ...$this->manifest($match, $this->participantRows($matchId, false)),
                'manifestHash' => self::base64UrlEncode($match['manifest_hash']),
            ];
        }
        return $payload;
    }

    private function publicParticipants(string $matchId, string $playerId): array
    {
        $statement = $this->database->prepare(
            'SELECT participant.participant_id, participant.player_id, participant.seat, '
            . 'participant.color_index, participant.ready, participant.status, '
            . 'player.nickname, player.nickname_confirmed, selection.pet_id, selection.is_visible '
            . 'FROM multiplayer_participants participant '
            . 'JOIN players player ON player.id = participant.player_id '
            . 'LEFT JOIN player_pet_selection selection ON selection.player_id = player.id '
            . 'WHERE participant.match_id = :match_id '
            . 'ORDER BY participant.seat ASC'
        );
        $statement->execute(['match_id' => $matchId]);
        $payload = [];
        foreach ($statement->fetchAll() as $row) {
            $payload[] = [
                'participantId' => (string) $row['participant_id'],
                'seat' => (int) $row['seat'],
                'colorIndex' => (int) $row['color_index'],
                'name' => (string) $row['nickname'],
                'petId' => $this->visiblePet(
                    $row['nickname'],
                    (bool) $row['nickname_confirmed'],
                    $row['pet_id'] ?? null,
                    (bool) ($row['is_visible'] ?? false),
                ),
                'ready' => (bool) $row['ready'],
                'status' => (string) $row['status'],
                'isCurrentPlayer' => hash_equals((string) $row['player_id'], $playerId),
            ];
        }
        return $payload;
    }

    private function manifest(array $match, array $participants): array
    {
        usort(
            $participants,
            static fn (array $left, array $right): int =>
                (int) $left['seat'] <=> (int) $right['seat'],
        );
        return [
            'protocolVersion' => (int) $match['protocol_version'],
            'ruleset' => (string) $match['ruleset_id'],
            'proofVersion' => (int) $match['proof_version'],
            'matchId' => (string) $match['id'],
            'buildId' => (string) $match['build_id'],
            'seed' => self::base64UrlEncode((string) $match['seed']),
            'startingLives' => MultiplayerCatalog::STARTING_LIVES,
            'participants' => array_map(
                static fn (array $participant): array => [
                    'participantId' => (string) $participant['participant_id'],
                    'seat' => (int) $participant['seat'],
                    'colorIndex' => (int) $participant['color_index'],
                ],
                $participants,
            ),
        ];
    }

    private function insertResults(
        string $matchId,
        array $results,
        string $status,
        array $verified,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO multiplayer_results '
            . '(id, match_id, season_id, player_id, participant_id, placement, player_count, '
            . 'score, duration_ms, fastest_reaction_ms, average_reaction_ms, correct_taps, '
            . 'miss_count, dodge_count, godlike_count, perfect_count, great_count, good_count, '
            . 'max_multiplier, verification_status, verification_method, risk_score, '
            . 'risk_reasons, achieved_at) VALUES '
            . '(:id, :match_id, :season_id, :player_id, :participant_id, :placement, '
            . ':player_count, :score, :duration_ms, :fastest_reaction_ms, '
            . ':average_reaction_ms, :correct_taps, :miss_count, :dodge_count, '
            . ':godlike_count, :perfect_count, :great_count, :good_count, :max_multiplier, '
            . ':verification_status, :verification_method, :risk_score, :risk_reasons, :achieved_at)'
        );
        foreach ($results as $result) {
            $statement->execute([
                'id' => Uuid::v4(),
                'match_id' => $matchId,
                'season_id' => $this->seasonId,
                'player_id' => $result['playerId'],
                'participant_id' => $result['participantId'],
                'placement' => $result['placement'],
                'player_count' => $result['playerCount'],
                'score' => $result['score'],
                'duration_ms' => $result['durationMs'],
                'fastest_reaction_ms' => $result['fastestReactionMs'],
                'average_reaction_ms' => $result['averageReactionMs'],
                'correct_taps' => $result['hits'],
                'miss_count' => $result['misses'],
                'dodge_count' => $result['dodges'],
                'godlike_count' => $result['godlikeCount'],
                'perfect_count' => $result['perfectCount'],
                'great_count' => $result['greatCount'],
                'good_count' => $result['goodCount'],
                'max_multiplier' => $result['maxMultiplier'],
                'verification_status' => $status,
                'verification_method' => 'peer_consistent_v1',
                'risk_score' => $verified['riskScore'],
                'risk_reasons' => json_encode(
                    $verified['riskFlags'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
                'achieved_at' => self::timestamp(),
            ]);
        }
    }

    private function settleMatch(string $matchId, string $status, array $verified): void
    {
        $state = $status === 'verified' ? 'settled' : 'review';
        $statement = $this->database->prepare(
            'UPDATE multiplayer_matches SET state = :state, transcript_hash = :transcript_hash, '
            . 'duration_ms = :duration_ms, risk_score = :risk_score, '
            . 'risk_reasons = :risk_reasons, review_reason = :review_reason, '
            . 'settled_at = :settled_at, updated_at = :updated_at WHERE id = :match_id'
        );
        $now = self::timestamp();
        $statement->execute([
            'state' => $state,
            'transcript_hash' => $verified['transcriptHash'],
            'duration_ms' => $verified['durationMs'],
            'risk_score' => $verified['riskScore'],
            'risk_reasons' => json_encode(
                $verified['riskFlags'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
            'review_reason' => $state === 'review'
                ? ($status === 'quarantined'
                    ? 'This transcript was already claimed by another match.'
                    : 'Automated risk checks withheld this result.')
                : null,
            'settled_at' => $now,
            'updated_at' => $now,
            'match_id' => $matchId,
        ]);
    }

    private function claimTrace(string $matchId, string $hash): bool
    {
        $statement = $this->database->prepare(
            'SELECT first_match_id FROM multiplayer_trace_claims '
            . 'WHERE trace_hash = :trace_hash' . $this->forUpdate()
        );
        $statement->execute(['trace_hash' => $hash]);
        $existing = $statement->fetchColumn();
        if (is_string($existing)) {
            return !hash_equals($existing, $matchId);
        }
        try {
            $insert = $this->database->prepare(
                'INSERT INTO multiplayer_trace_claims '
                . '(trace_hash, first_match_id, claimed_at) VALUES '
                . '(:trace_hash, :match_id, :claimed_at)'
            );
            $insert->execute([
                'trace_hash' => $hash,
                'match_id' => $matchId,
                'claimed_at' => self::timestamp(),
            ]);
            return false;
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') throw $error;
            return true;
        }
    }

    private function assertEligiblePlayer(string $playerId, bool $requireFresh): array
    {
        $statement = $this->database->prepare(
            'SELECT player.id, player.nickname_confirmed, binding.game_player_id_hash, '
            . 'binding.last_verified_at, binding.publication_enabled_at, '
            . 'binding.publication_disabled_at '
            . 'FROM players player '
            . 'LEFT JOIN player_game_center_bindings binding ON binding.player_id = player.id '
            . 'WHERE player.id = :player_id LIMIT 1'
        );
        $statement->execute(['player_id' => $playerId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(401, 'Sign in to continue.');
        }
        if ((int) $row['nickname_confirmed'] !== 1) {
            throw new ApiException(409, 'Choose a public nickname before multiplayer.');
        }
        if (
            !is_string($row['game_player_id_hash'])
            || $row['publication_enabled_at'] === null
            || $row['publication_disabled_at'] !== null
        ) {
            throw new ApiException(409, 'Connect Game Center before multiplayer.');
        }
        if ($requireFresh) {
            if (!is_string($row['last_verified_at']) || $row['last_verified_at'] === '') {
                throw new ApiException(
                    409,
                    'Refresh the Game Center connection before multiplayer.',
                );
            }
            $lastVerified = self::parseDate($row['last_verified_at']);
            if ($lastVerified < self::now()->modify('-' . self::GAME_CENTER_FRESHNESS_SECONDS . ' seconds')) {
                throw new ApiException(
                    409,
                    'Refresh the Game Center connection before multiplayer.',
                );
            }
        }
        return $row;
    }

    private function gameCenterBindings(array $playerIds): array
    {
        if ($playerIds === []) return [];
        $placeholders = [];
        $parameters = [];
        foreach (array_values($playerIds) as $index => $playerId) {
            $key = 'player_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $playerId;
        }
        $statement = $this->database->prepare(
            'SELECT player_id, game_player_id_hash FROM player_game_center_bindings '
            . 'WHERE publication_enabled_at IS NOT NULL AND publication_disabled_at IS NULL '
            . 'AND player_id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($parameters);
        $bindings = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_string($row['game_player_id_hash'])) {
                $bindings[(string) $row['player_id']] = $row['game_player_id_hash'];
            }
        }
        return $bindings;
    }

    private function insertParticipant(
        string $matchId,
        string $participantId,
        string $playerId,
        int $seat,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO multiplayer_participants '
            . '(match_id, participant_id, player_id, seat, color_index, ready, status, '
            . 'joined_at, updated_at) VALUES '
            . "(:match_id, :participant_id, :player_id, :seat, :color_index, 0, 'joined', "
            . ':joined_at, :updated_at)'
        );
        $now = self::timestamp();
        $statement->execute([
            'match_id' => $matchId,
            'participant_id' => $participantId,
            'player_id' => $playerId,
            'seat' => $seat,
            'color_index' => $seat,
            'joined_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function compactSeats(string $matchId, array $participants): void
    {
        usort(
            $participants,
            static fn (array $left, array $right): int =>
                (int) $left['seat'] <=> (int) $right['seat'],
        );
        $update = $this->database->prepare(
            'UPDATE multiplayer_participants SET seat = :seat, color_index = :color_index '
            . 'WHERE match_id = :match_id AND player_id = :player_id'
        );
        foreach ($participants as $seat => $participant) {
            if (
                (int) $participant['seat'] === $seat
                && (int) $participant['color_index'] === $seat
            ) {
                continue;
            }
            // A forming-lobby departure can only move a participant toward a
            // lower contiguous seat. Updating in ascending old-seat order
            // means each destination has already been vacated, so this stays
            // inside the production CHECK constraints and never collides with
            // the unique (match_id, seat) key.
            $update->execute([
                'seat' => $seat,
                'color_index' => $seat,
                'match_id' => $matchId,
                'player_id' => $participant['player_id'],
            ]);
        }
        $this->database->prepare(
            'DELETE FROM multiplayer_roster_confirmations WHERE match_id = :match_id'
        )->execute(['match_id' => $matchId]);
    }

    private function upsertRosterConfirmation(
        string $matchId,
        string $playerId,
        string $rosterHash,
        string $coordinatorParticipantId,
    ): void {
        $delete = $this->database->prepare(
            'DELETE FROM multiplayer_roster_confirmations '
            . 'WHERE match_id = :match_id AND player_id = :player_id'
        );
        $delete->execute(['match_id' => $matchId, 'player_id' => $playerId]);
        $insert = $this->database->prepare(
            'INSERT INTO multiplayer_roster_confirmations '
            . '(match_id, player_id, roster_hash, coordinator_participant_id, confirmed_at) '
            . 'VALUES (:match_id, :player_id, :roster_hash, :coordinator, :confirmed_at)'
        );
        $insert->execute([
            'match_id' => $matchId,
            'player_id' => $playerId,
            'roster_hash' => $rosterHash,
            'coordinator' => $coordinatorParticipantId,
            'confirmed_at' => self::timestamp(),
        ]);
    }

    private function confirmationCount(string $matchId): int
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM multiplayer_roster_confirmations WHERE match_id = :match_id'
        );
        $statement->execute(['match_id' => $matchId]);
        return (int) $statement->fetchColumn();
    }

    private function rosterConfirmations(string $matchId, bool $lock): array
    {
        $statement = $this->database->prepare(
            'SELECT player_id, roster_hash, coordinator_participant_id '
            . 'FROM multiplayer_roster_confirmations WHERE match_id = :match_id '
            . 'ORDER BY player_id ASC' . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute(['match_id' => $matchId]);
        return $statement->fetchAll();
    }

    private function match(string $matchId, bool $lock): array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM multiplayer_matches WHERE id = :match_id LIMIT 1'
            . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute(['match_id' => $matchId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Multiplayer match was not found.');
        }
        return $row;
    }

    private function lockMatch(string $matchId): array
    {
        return $this->match($matchId, true);
    }

    private function participant(string $matchId, string $playerId, bool $lock): ?array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM multiplayer_participants '
            . 'WHERE match_id = :match_id AND player_id = :player_id LIMIT 1'
            . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute(['match_id' => $matchId, 'player_id' => $playerId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function requireMember(string $matchId, string $playerId, bool $lock): array
    {
        return $this->participant($matchId, $playerId, $lock)
            ?? throw new ApiException(404, 'Multiplayer match was not found.');
    }

    private function participantRows(string $matchId, bool $lock): array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM multiplayer_participants WHERE match_id = :match_id '
            . 'ORDER BY seat ASC' . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute(['match_id' => $matchId]);
        return $statement->fetchAll();
    }

    private function submission(string $matchId, string $playerId, bool $lock): ?array
    {
        $statement = $this->database->prepare(
            'SELECT manifest_hash, transcript_hash FROM multiplayer_submissions '
            . 'WHERE match_id = :match_id AND player_id = :player_id LIMIT 1'
            . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute(['match_id' => $matchId, 'player_id' => $playerId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function submissions(string $matchId, bool $lock): array
    {
        $statement = $this->database->prepare(
            'SELECT player_id, manifest_hash, transcript_hash, proof_json '
            . 'FROM multiplayer_submissions WHERE match_id = :match_id '
            . 'ORDER BY player_id ASC' . ($lock ? $this->forUpdate() : '')
        );
        $statement->execute(['match_id' => $matchId]);
        return $statement->fetchAll();
    }

    private function setCollecting(string $matchId): void
    {
        $statement = $this->database->prepare(
            "UPDATE multiplayer_matches SET state = 'collecting', updated_at = :updated_at "
            . "WHERE id = :match_id AND state = 'active'"
        );
        $statement->execute([
            'updated_at' => self::timestamp(),
            'match_id' => $matchId,
        ]);
    }

    private function markMatch(string $matchId, string $state, ?string $reason): void
    {
        $statement = $this->database->prepare(
            'UPDATE multiplayer_matches SET state = :state, review_reason = :reason, '
            . 'updated_at = :updated_at WHERE id = :match_id'
        );
        $statement->execute([
            'state' => $state,
            'reason' => $reason,
            'updated_at' => self::timestamp(),
            'match_id' => $matchId,
        ]);
    }

    private function cancelMatch(string $matchId, ?string $reason): void
    {
        foreach (
            [
                ['multiplayer_submissions', 'match_id'],
                ['multiplayer_trace_claims', 'first_match_id'],
                ['multiplayer_roster_confirmations', 'match_id'],
            ] as [$table, $column]
        ) {
            $statement = $this->database->prepare(
                'DELETE FROM ' . $table . ' WHERE ' . $column . ' = :match_id'
            );
            $statement->execute(['match_id' => $matchId]);
        }
        $statement = $this->database->prepare(
            "UPDATE multiplayer_matches SET state = 'cancelled', manifest_hash = NULL, "
            . 'roster_hash = NULL, coordinator_participant_id = NULL, transcript_hash = NULL, '
            . 'duration_ms = NULL, risk_score = 0, risk_reasons = NULL, '
            . 'review_reason = :reason, started_at = NULL, submission_deadline_at = NULL, '
            . 'settled_at = NULL, updated_at = :updated_at WHERE id = :match_id'
        );
        $statement->execute([
            'reason' => $reason,
            'updated_at' => self::timestamp(),
            'match_id' => $matchId,
        ]);
    }

    private function assertForming(array $match): void
    {
        if ((string) $match['state'] !== 'forming') {
            throw new ApiException(409, 'This multiplayer lobby is no longer forming.');
        }
        if (self::parseDate((string) $match['expires_at']) <= self::now()) {
            throw new ApiException(409, 'This multiplayer lobby expired.');
        }
    }

    private function expireFormingMatchIfStale(string $matchId): void
    {
        $statement = $this->database->prepare(
            "UPDATE multiplayer_matches SET state = 'expired', review_reason = NULL, "
            . 'updated_at = :updated_at '
            . "WHERE id = :match_id AND state = 'forming' AND expires_at <= :expires_at"
        );
        $now = self::timestamp();
        $statement->execute([
            'updated_at' => $now,
            'match_id' => $matchId,
            'expires_at' => $now,
        ]);
        if ($statement->rowCount() > 0) {
            throw new ApiException(409, 'This multiplayer lobby expired.');
        }
    }

    private function expireStaleLobbies(): void
    {
        $statement = $this->database->prepare(
            "UPDATE multiplayer_matches SET state = 'expired', updated_at = :updated_at "
            . "WHERE state = 'forming' AND expires_at <= :expires_at"
        );
        $now = self::timestamp();
        $statement->execute([
            'updated_at' => $now,
            'expires_at' => $now,
        ]);
    }

    private function enforceCreateRateLimit(string $playerId, DateTimeImmutable $now): void
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM multiplayer_lobby_creation_events '
            . 'WHERE player_id = :player_id AND created_at >= :cutoff'
        );
        $statement->execute([
            'player_id' => $playerId,
            'cutoff' => self::timestamp($now->modify('-10 minutes')),
        ]);
        if ((int) $statement->fetchColumn() >= self::CREATE_LIMIT_PER_TEN_MINUTES) {
            throw new ApiException(429, 'Too many multiplayer lobbies were created. Try again later.');
        }
    }

    private function lockCreateRatePlayer(string $playerId): void
    {
        $statement = $this->database->prepare(
            'SELECT id FROM players WHERE id = :player_id LIMIT 1' . $this->forUpdate()
        );
        $statement->execute(['player_id' => $playerId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException(401, 'Sign in again before creating a multiplayer lobby.');
        }
    }

    private function recordLobbyCreation(
        string $matchId,
        string $playerId,
        DateTimeImmutable $createdAt,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO multiplayer_lobby_creation_events (match_id, player_id, created_at) '
            . 'VALUES (:match_id, :player_id, :created_at)'
        );
        $statement->execute([
            'match_id' => $matchId,
            'player_id' => $playerId,
            'created_at' => self::timestamp($createdAt),
        ]);
    }

    private function enforceSubmissionRateLimit(string $playerId): void
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM multiplayer_submissions '
            . 'WHERE player_id = :player_id AND submitted_at >= :cutoff'
        );
        $statement->execute([
            'player_id' => $playerId,
            'cutoff' => self::timestamp(self::now()->modify('-1 hour')),
        ]);
        if ((int) $statement->fetchColumn() >= self::SUBMISSION_LIMIT_PER_HOUR) {
            throw new ApiException(429, 'Too many multiplayer results were submitted. Try again later.');
        }
    }

    private function uniquePlayerGroup(): int
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = random_int(1, 2_147_483_647);
            $statement = $this->database->prepare(
                'SELECT 1 FROM multiplayer_matches WHERE player_group = :player_group LIMIT 1'
            );
            $statement->execute(['player_group' => $candidate]);
            if ($statement->fetchColumn() === false) return $candidate;
        }
        throw new ApiException(503, 'Could not allocate a GameKit player group.');
    }

    private function lowestFreeSeat(array $participants): int
    {
        $used = [];
        foreach ($participants as $participant) {
            $used[(int) $participant['seat']] = true;
        }
        for ($seat = 0; $seat < MultiplayerCatalog::MAX_PLAYERS; $seat++) {
            if (!isset($used[$seat])) return $seat;
        }
        throw new ApiException(409, 'This multiplayer lobby is full.');
    }

    private function elapsedMilliseconds(string $startedAt): int
    {
        $started = self::parseDate($startedAt);
        $seconds = (float) self::now()->format('U.u') - (float) $started->format('U.u');
        return max(0, (int) floor($seconds * 1_000));
    }

    private function visiblePet(
        mixed $nickname,
        bool $nicknameConfirmed,
        mixed $selectedPetId,
        bool $visible,
    ): ?string {
        $special = PetCatalog::specialForNickname($nickname, $nicknameConfirmed);
        if ($special !== null) return $special;
        return $visible && PetCatalog::isRenderable($selectedPetId)
            ? (string) $selectedPetId
            : null;
    }

    private function constantTimeHashListEquals(array $left, array $right): bool
    {
        if (count($left) !== count($right)) return false;
        foreach ($left as $index => $hash) {
            if (!hash_equals($hash, $right[$index])) return false;
        }
        return true;
    }

    private function transaction(callable $operation): mixed
    {
        if ($this->database->inTransaction()) {
            throw new LogicException('A multiplayer operation must own its transaction.');
        }
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

    private function forUpdate(): string
    {
        return $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
    }

    private static function matchId(string $value): string
    {
        $value = strtolower(trim($value));
        if (!Uuid::isValidV4($value)) {
            throw new ApiException(404, 'Multiplayer match was not found.');
        }
        return $value;
    }

    private static function gamePlayerId(mixed $value): string
    {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > 255
            || preg_match('/[\\x00-\\x1f\\x7f]/', $value) === 1
        ) {
            throw new ApiException(400, 'A Game Center player identifier is invalid.');
        }
        return $value;
    }

    private static function gamePlayerIdHash(string $value): string
    {
        return hash('sha256', "game_center_game_player\0" . $value, true);
    }

    private static function canonicalJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode32(mixed $value, string $name): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) !== 1) {
            throw new ApiException(400, 'Multiplayer ' . $name . ' is invalid.');
        }
        $decoded = base64_decode(strtr($value . '=', '-_', '+/'), true);
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            throw new ApiException(400, 'Multiplayer ' . $name . ' is invalid.');
        }
        return $decoded;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function timestamp(?DateTimeImmutable $value = null): string
    {
        return ($value ?? self::now())->format('Y-m-d H:i:s.v');
    }

    private static function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private static function isoDate(string $value): string
    {
        return self::parseDate($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
