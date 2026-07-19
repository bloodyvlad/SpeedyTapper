<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;
use PDOException;

final class AppStoreNotificationService
{
    private const TRANSACTION_EVENTS = [
        'ONE_TIME_CHARGE',
        'REFUND',
        'REFUND_REVERSED',
        'REVOKE',
    ];

    public function __construct(
        private readonly PDO $database,
        private readonly Config $config,
        private readonly AppleJwsVerifier $verifier,
        private readonly StoreKitNotificationProcessor $storeKit,
    ) {
    }

    /** @return array{notificationUUID: string, status: string, duplicate: bool, transactionId: ?string} */
    public function receive(mixed $signedPayload): array
    {
        if (!is_string($signedPayload) || $signedPayload === '' || strlen($signedPayload) > 262_144) {
            throw new ApiException(400, 'A signed App Store notification is required.');
        }
        try {
            $payload = $this->verifier->verify($signedPayload);
        } catch (AppleJwsVerificationException) {
            throw new ApiException(400, 'The App Store notification signature could not be verified.');
        }

        $notificationType = $this->notificationType($payload['notificationType'] ?? null);
        $subtype = $this->optionalToken($payload['subtype'] ?? null, 64);
        $signedDateMs = $this->positiveInteger($payload['signedDate'] ?? null, 'signed date');
        if (($payload['version'] ?? null) !== '2.0') {
            throw new ApiException(400, 'The App Store notification version is invalid.');
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw new ApiException(400, 'The App Store notification data is missing.');
        }
        $environment = $this->validateApp($data);
        $notificationUuid = $this->notificationUuid($payload['notificationUUID'] ?? null);
        $notificationStorageId = $this->notificationStorageId($environment, $notificationUuid);
        $payloadHash = hash('sha256', $signedPayload, true);
        $existing = $this->findNotification($notificationStorageId);
        if (is_array($existing)) {
            if (!hash_equals((string) $existing['payload_hash'], $payloadHash)) {
                throw new ApiException(409, 'The App Store notification identifier conflicts with retained evidence.');
            }
            return [
                'notificationUUID' => $notificationUuid,
                'status' => (string) $existing['processing_status'],
                'duplicate' => true,
                'transactionId' => is_string($existing['transaction_id'] ?? null)
                    ? StoreKitTransaction::appleIdFromStorage($existing['transaction_id'])
                    : null,
            ];
        }

        $transactionResult = null;
        $status = 'ignored';
        if (in_array($notificationType, self::TRANSACTION_EVENTS, true)) {
            $signedTransaction = $data['signedTransactionInfo'] ?? null;
            if (!is_string($signedTransaction) || $signedTransaction === '') {
                throw new ApiException(400, 'The App Store notification transaction is missing.');
            }
            $transactionResult = $this->storeKit->processNotificationTransaction(
                $signedTransaction,
                $notificationType,
                $signedDateMs,
                $environment,
            );
            $status = $transactionResult['status'] === 'ignored' ? 'ignored' : 'processed';
        } elseif ($notificationType === 'CONSUMPTION_REQUEST') {
            // The signed request is retained for audit/idempotency. Sending
            // consumption details to Apple requires a separate reviewed policy.
            $status = 'ignored';
        }

        try {
            $statement = $this->database->prepare(
                'INSERT INTO storekit_notifications '
                . '(notification_uuid, apple_notification_uuid, transaction_id, notification_type, subtype, environment, '
                . 'signed_date_ms, payload_hash, processing_status) VALUES '
                . '(:notification_uuid, :apple_notification_uuid, :transaction_id, :notification_type, :subtype, :environment, '
                . ':signed_date_ms, :payload_hash, :processing_status)'
            );
            $statement->bindValue(':notification_uuid', $notificationStorageId);
            $statement->bindValue(':apple_notification_uuid', $notificationUuid);
            $transactionStorageId = isset($transactionResult['transactionId'])
                ? StoreKitTransaction::storageIdFor($environment, $transactionResult['transactionId'])
                : null;
            $statement->bindValue(
                ':transaction_id',
                $transactionStorageId,
                $transactionStorageId === null ? PDO::PARAM_NULL : PDO::PARAM_STR,
            );
            $statement->bindValue(':notification_type', $notificationType);
            $statement->bindValue(':subtype', $subtype, $subtype === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->bindValue(':environment', $environment);
            $statement->bindValue(':signed_date_ms', $signedDateMs, PDO::PARAM_INT);
            $statement->bindValue(':payload_hash', $payloadHash, PDO::PARAM_LOB);
            $statement->bindValue(':processing_status', $status);
            $statement->execute();
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') throw $error;
            $winner = $this->findNotification($notificationStorageId);
            if (!is_array($winner) || !hash_equals((string) $winner['payload_hash'], $payloadHash)) {
                throw new ApiException(409, 'The App Store notification identifier conflicts with retained evidence.');
            }
            return [
                'notificationUUID' => $notificationUuid,
                'status' => (string) $winner['processing_status'],
                'duplicate' => true,
                'transactionId' => is_string($winner['transaction_id'] ?? null)
                    ? StoreKitTransaction::appleIdFromStorage($winner['transaction_id'])
                    : null,
            ];
        }

        return [
            'notificationUUID' => $notificationUuid,
            'status' => $status,
            'duplicate' => false,
            'transactionId' => $transactionResult['transactionId'] ?? null,
        ];
    }

    private function validateApp(array $data): string
    {
        if (($data['bundleId'] ?? null) !== $this->config->storeKitBundleId) {
            throw new ApiException(400, 'The App Store notification bundle does not match PimPoPom.');
        }
        $environment = $data['environment'] ?? null;
        if (!is_string($environment) || !$this->config->acceptsStoreKitEnvironment($environment)) {
            throw new ApiException(400, 'The App Store notification environment is not accepted here.');
        }
        $appAppleId = $data['appAppleId'] ?? null;
        if ($environment === 'Production') {
            if (!is_int($appAppleId) && !is_string($appAppleId)) {
                throw new ApiException(400, 'The App Store app identifier is missing.');
            }
            if (!hash_equals((string) $this->config->storeKitAppAppleId, (string) $appAppleId)) {
                throw new ApiException(400, 'The App Store app identifier does not match PimPoPom.');
            }
        } elseif ($appAppleId !== null && $this->config->storeKitAppAppleId !== null
            && !hash_equals($this->config->storeKitAppAppleId, (string) $appAppleId)
        ) {
            throw new ApiException(400, 'The App Store app identifier does not match PimPoPom.');
        }
        return $environment;
    }

    private function findNotification(string $notificationUuid): array|false
    {
        $statement = $this->database->prepare(
            'SELECT transaction_id, payload_hash, processing_status FROM storekit_notifications '
            . 'WHERE notification_uuid = :notification_uuid LIMIT 1'
        );
        $statement->execute(['notification_uuid' => $notificationUuid]);
        return $statement->fetch();
    }

    private function notificationUuid(mixed $value): string
    {
        if (!is_string($value) || preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab0-9a-f][0-9a-f]{3}-[0-9a-f]{12}$/Di',
            $value,
        ) !== 1) {
            throw new ApiException(400, 'The App Store notification identifier is invalid.');
        }
        return strtolower($value);
    }

    private function notificationStorageId(string $environment, string $notificationUuid): string
    {
        if (!$this->config->acceptsStoreKitEnvironment($environment)) {
            throw new ApiException(400, 'The App Store notification environment is not accepted here.');
        }
        return $environment . ':' . $notificationUuid;
    }

    private function notificationType(mixed $value): string
    {
        $type = $this->optionalToken($value, 64);
        if ($type === null) {
            throw new ApiException(400, 'The App Store notification type is invalid.');
        }
        return $type;
    }

    private function optionalToken(mixed $value, int $maximum): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) || strlen($value) > $maximum
            || preg_match('/^[A-Z0-9_]+$/D', $value) !== 1
        ) {
            throw new ApiException(400, 'The App Store notification field is invalid.');
        }
        return $value;
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new ApiException(400, 'The App Store notification ' . $label . ' is invalid.');
        }
        return $value;
    }
}
