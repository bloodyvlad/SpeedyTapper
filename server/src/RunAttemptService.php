<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use Throwable;

final class RunAttemptService
{
    private const START_LIMIT_PER_MINUTE = 12;
    private const START_LIMIT_PER_DAY = 500;
    private const LEASE_HOURS = 24;

    public function __construct(private readonly PDO $database)
    {
    }

    public function start(
        string $playerId,
        string $sessionBindingHash,
        mixed $mode,
        mixed $buildId,
    ): array
    {
        if ($mode === 'zen') {
            throw new ApiException(409, 'Zen is endless unranked practice and does not issue ranked run tickets.');
        }
        if ($mode !== 'normal') {
            throw new ApiException(400, 'Ranked mode must be normal.');
        }
        if (!RunProof::isSupportedBuildId($buildId)) {
            throw new ApiException(409, 'This game version is out of date. Refresh before starting a ranked run.');
        }
        self::assertBindingHash($sessionBindingHash);
        if (!Uuid::isValidV4($playerId)) {
            throw new ApiException(401, 'Sign in with Google to start a ranked run.');
        }

        $this->database->beginTransaction();
        try {
            $playerLock = $this->database->prepare(
                'SELECT id FROM players WHERE id = :player_id FOR UPDATE'
            );
            $playerLock->execute(['player_id' => $playerId]);
            if ($playerLock->fetchColumn() === false) {
                throw new ApiException(401, 'Sign in with Google to start a ranked run.');
            }

            $rate = $this->database->prepare(
                'SELECT run_id FROM run_attempts WHERE player_id = :player_id '
                . 'AND started_at > UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE '
                . 'ORDER BY started_at DESC LIMIT ' . self::START_LIMIT_PER_MINUTE . ' FOR UPDATE'
            );
            $rate->bindValue(':player_id', $playerId);
            $rate->execute();
            if (count($rate->fetchAll(PDO::FETCH_COLUMN)) >= self::START_LIMIT_PER_MINUTE) {
                throw new ApiException(429, 'Too many runs were started. Wait a minute and try again.', [
                    'Retry-After' => '60',
                ]);
            }
            $dailyRate = $this->database->prepare(
                'SELECT COUNT(*) FROM run_attempts WHERE player_id = :player_id '
                . 'AND started_at > UTC_TIMESTAMP(3) - INTERVAL 1 DAY'
            );
            $dailyRate->execute(['player_id' => $playerId]);
            if ((int) $dailyRate->fetchColumn() >= self::START_LIMIT_PER_DAY) {
                throw new ApiException(429, 'The daily ranked-run limit has been reached. Try again tomorrow.', [
                    'Retry-After' => '3600',
                ]);
            }

            $abandon = $this->database->prepare(
                "UPDATE run_attempts SET status = 'abandoned' "
                . "WHERE player_id = :player_id AND status = 'issued'"
            );
            $abandon->bindValue(':player_id', $playerId);
            $abandon->execute();

            $runId = Uuid::v4();
            $insert = $this->database->prepare(
                'INSERT INTO run_attempts '
                . '(run_id, session_binding_hash, player_id, mode, build_id, ruleset_id, proof_version, '
                . 'status, started_at, expires_at) VALUES '
                . '(:run_id, :binding_hash, :player_id, :mode, :build_id, :ruleset_id, :proof_version, '
                . "'issued', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3) + INTERVAL " . self::LEASE_HOURS . ' HOUR)'
            );
            $insert->bindValue(':run_id', $runId);
            $insert->bindValue(':binding_hash', $sessionBindingHash, PDO::PARAM_LOB);
            $insert->bindValue(':player_id', $playerId);
            $insert->bindValue(':mode', $mode);
            $insert->bindValue(':build_id', $buildId);
            $insert->bindValue(':ruleset_id', RunProofValidator::RULESET_ID);
            $insert->bindValue(':proof_version', RunProofValidator::PROOF_VERSION, PDO::PARAM_INT);
            $insert->execute();

            $this->database->commit();
            return [
                'runId' => $runId,
                'mode' => $mode,
                'buildId' => $buildId,
                'ruleset' => RunProofValidator::RULESET_ID,
                'proofVersion' => RunProofValidator::PROOF_VERSION,
            ];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function abandon(string $sessionBindingHash, mixed $runId): void
    {
        self::assertBindingHash($sessionBindingHash);
        if (!is_string($runId) || !Uuid::isValidV4($runId)) {
            throw new ApiException(400, 'Run ID is invalid.');
        }
        $statement = $this->database->prepare(
            "UPDATE run_attempts SET status = 'abandoned' "
            . "WHERE run_id = :run_id AND session_binding_hash = :binding_hash AND status = 'issued'"
        );
        $statement->bindValue(':run_id', strtolower($runId));
        $statement->bindValue(':binding_hash', $sessionBindingHash, PDO::PARAM_LOB);
        $statement->execute();
    }

    private static function assertBindingHash(string $hash): void
    {
        if (strlen($hash) !== 32) {
            throw new ApiException(403, 'Run session is invalid. Refresh and try again.');
        }
    }
}
