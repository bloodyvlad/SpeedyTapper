<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use Throwable;

/** Stores only encrypted Apple refresh material needed for later revocation. */
final class AppleCredentialRepository
{
    private string $encryptionKey;

    public function __construct(
        private readonly PDO $database,
        string $encryptionSecret,
    ) {
        if (strlen($encryptionSecret) < 32) {
            throw new \InvalidArgumentException('Apple credential encryption secret must contain at least 32 bytes.');
        }
        $this->encryptionKey = hash_hkdf(
            'sha256',
            $encryptionSecret,
            32,
            'pimpopom-apple-refresh-v1',
        );
    }

    public function storeOrRetain(
        string $playerId,
        string $appleSubject,
        ?string $refreshToken,
    ): void {
        if ($this->database->inTransaction()) {
            throw new \InvalidArgumentException(
                'Use storeOrRetainInCurrentTransaction inside an identity transaction.',
            );
        }
        $this->database->beginTransaction();
        try {
            $this->storeOrRetainInCurrentTransaction($playerId, $appleSubject, $refreshToken);
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    public function storeOrRetainInCurrentTransaction(
        string $playerId,
        string $appleSubject,
        ?string $refreshToken,
    ): void {
        if (!$this->database->inTransaction()) {
            throw new \InvalidArgumentException('An identity transaction is required.');
        }
        $playerId = strtolower(trim($playerId));
        if (!Uuid::isValidV4($playerId)) {
            throw new ApiException(401, 'Sign in again to continue.');
        }
        $subjectHash = PlayerIdentityService::subjectHash(
            PlayerIdentityService::PROVIDER_APPLE,
            $appleSubject,
        );
        $forUpdate = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
        $identity = $this->database->prepare(
            "SELECT player_id FROM player_identities WHERE provider = 'apple' "
            . 'AND subject_hash = :subject_hash LIMIT 1' . $forUpdate
        );
        $identity->bindValue(':subject_hash', $subjectHash, PDO::PARAM_LOB);
        $identity->execute();
        $owner = $identity->fetchColumn();
        if (!is_string($owner) || !hash_equals($playerId, strtolower($owner))) {
            throw new ApiException(409, 'Apple authorization is not linked to this PimPoPom profile.');
        }

        $existing = $this->database->prepare(
            'SELECT subject_hash FROM player_apple_authorizations '
            . 'WHERE player_id = :player_id LIMIT 1' . $forUpdate
        );
        $existing->execute(['player_id' => $playerId]);
        $existingHash = $existing->fetchColumn();
        if (is_string($existingHash)) {
            if (!hash_equals($existingHash, $subjectHash)) {
                throw new ApiException(409, 'A different Apple authorization is already retained for this profile.');
            }
            if ($refreshToken !== null) {
                $this->updateEncryptedToken($playerId, $subjectHash, $refreshToken);
            }
            return;
        }
        if ($refreshToken === null) {
            throw new ApiException(503, 'Apple did not return revocation material for this new account link.');
        }
        [$ciphertext, $iv, $tag] = $this->encrypt($playerId, $subjectHash, $refreshToken);
        $insert = $this->database->prepare(
            'INSERT INTO player_apple_authorizations '
            . '(player_id, provider, subject_hash, refresh_token_ciphertext, refresh_token_iv, refresh_token_tag) '
            . "VALUES (:player_id, 'apple', :subject_hash, :ciphertext, :iv, :tag)"
        );
        $insert->bindValue(':player_id', $playerId);
        $insert->bindValue(':subject_hash', $subjectHash, PDO::PARAM_LOB);
        $insert->bindValue(':ciphertext', $ciphertext, PDO::PARAM_LOB);
        $insert->bindValue(':iv', $iv, PDO::PARAM_LOB);
        $insert->bindValue(':tag', $tag, PDO::PARAM_LOB);
        $insert->execute();
    }

    public function refreshTokenForDeletion(string $playerId): ?string
    {
        $playerId = strtolower(trim($playerId));
        if (!Uuid::isValidV4($playerId)) {
            throw new ApiException(401, 'Sign in again to continue.');
        }
        $statement = $this->database->prepare(
            'SELECT subject_hash, refresh_token_ciphertext, refresh_token_iv, refresh_token_tag '
            . 'FROM player_apple_authorizations WHERE player_id = :player_id LIMIT 1'
        );
        $statement->execute(['player_id' => $playerId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        foreach (['subject_hash', 'refresh_token_ciphertext', 'refresh_token_iv', 'refresh_token_tag'] as $field) {
            if (!is_string($row[$field] ?? null)) {
                throw new ApiException(503, 'Retained Apple revocation material is invalid.');
            }
        }
        $plaintext = openssl_decrypt(
            $row['refresh_token_ciphertext'],
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $row['refresh_token_iv'],
            $row['refresh_token_tag'],
            $this->additionalData($playerId, $row['subject_hash']),
        );
        if (!is_string($plaintext) || $plaintext === '') {
            throw new ApiException(503, 'Retained Apple revocation material could not be decrypted.');
        }
        return $plaintext;
    }

    private function updateEncryptedToken(string $playerId, string $subjectHash, string $refreshToken): void
    {
        [$ciphertext, $iv, $tag] = $this->encrypt($playerId, $subjectHash, $refreshToken);
        $update = $this->database->prepare(
            'UPDATE player_apple_authorizations SET refresh_token_ciphertext = :ciphertext, '
            . 'refresh_token_iv = :iv, refresh_token_tag = :tag, updated_at = :updated_at '
            . 'WHERE player_id = :player_id AND subject_hash = :subject_hash'
        );
        $update->bindValue(':ciphertext', $ciphertext, PDO::PARAM_LOB);
        $update->bindValue(':iv', $iv, PDO::PARAM_LOB);
        $update->bindValue(':tag', $tag, PDO::PARAM_LOB);
        $update->bindValue(':updated_at', gmdate('Y-m-d H:i:s'));
        $update->bindValue(':player_id', $playerId);
        $update->bindValue(':subject_hash', $subjectHash, PDO::PARAM_LOB);
        $update->execute();
    }

    /** @return array{string, string, string} */
    private function encrypt(string $playerId, string $subjectHash, string $refreshToken): array
    {
        if ($refreshToken === '' || strlen($refreshToken) > 4_096) {
            throw new ApiException(401, 'Apple refresh token is invalid.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $refreshToken,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->additionalData($playerId, $subjectHash),
            16,
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new ApiException(503, 'Apple revocation material could not be encrypted.');
        }
        return [$ciphertext, $iv, $tag];
    }

    private function additionalData(string $playerId, string $subjectHash): string
    {
        return "pimpopom-apple-refresh-v1\0" . $playerId . "\0" . $subjectHash;
    }
}
