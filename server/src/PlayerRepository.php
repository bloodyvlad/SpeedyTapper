<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;

final class PlayerRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function find(string $playerId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, nickname, created_at, updated_at FROM players WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $playerId]);
        $row = $statement->fetch();
        return is_array($row) ? $this->publicProfile($row) : null;
    }

    public function findOrCreate(GoogleIdentity $identity): array
    {
        $subjectHash = hash('sha256', "google\0" . $identity->subject, true);
        $statement = $this->database->prepare(
            'SELECT id, nickname, created_at, updated_at FROM players WHERE google_subject_hash = :subject_hash LIMIT 1'
        );
        $statement->bindValue('subject_hash', $subjectHash, PDO::PARAM_LOB);
        $statement->execute();
        $existing = $statement->fetch();
        if (is_array($existing)) {
            $this->database->prepare(
                'UPDATE players SET last_login_at = UTC_TIMESTAMP(3) WHERE id = :id'
            )->execute(['id' => $existing['id']]);
            return $this->publicProfile($existing);
        }

        $id = Uuid::v4();
        try {
            $insert = $this->database->prepare(
                'INSERT INTO players (id, google_subject_hash, nickname, last_login_at) '
                . 'VALUES (:id, :subject_hash, :nickname, UTC_TIMESTAMP(3))'
            );
            $insert->bindValue('id', $id);
            $insert->bindValue('subject_hash', $subjectHash, PDO::PARAM_LOB);
            $insert->bindValue('nickname', $identity->suggestedNickname);
            $insert->execute();
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }
            $statement->execute();
            $winner = $statement->fetch();
            if (!is_array($winner)) {
                throw $error;
            }
            return $this->publicProfile($winner);
        }

        return $this->find($id) ?? throw new \RuntimeException('Created profile could not be loaded.');
    }

    public function updateNickname(string $playerId, string $nickname): array
    {
        $normalized = Nickname::normalize($nickname);
        $statement = $this->database->prepare(
            'UPDATE players SET nickname = :nickname, updated_at = UTC_TIMESTAMP(3) WHERE id = :id'
        );
        $statement->execute(['nickname' => $normalized, 'id' => $playerId]);
        if ($statement->rowCount() === 0 && $this->find($playerId) === null) {
            throw new ApiException(401, 'Sign in again to update this profile.');
        }
        return $this->find($playerId) ?? throw new ApiException(401, 'Sign in again to update this profile.');
    }

    private function publicProfile(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'nickname' => (string) $row['nickname'],
            'createdAt' => self::isoDate((string) $row['created_at']),
            'updatedAt' => self::isoDate((string) $row['updated_at']),
        ];
    }

    private static function isoDate(string $value): string
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
