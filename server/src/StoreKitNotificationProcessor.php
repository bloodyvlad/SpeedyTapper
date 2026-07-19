<?php

declare(strict_types=1);

namespace SpeedyTapper;

interface StoreKitNotificationProcessor
{
    /** @return array{transactionId: string, status: string, duplicate: bool} */
    public function processNotificationTransaction(
        string $signedTransaction,
        string $notificationType,
        ?int $notificationSignedDateMs = null,
        ?string $expectedEnvironment = null,
    ): array;
}
