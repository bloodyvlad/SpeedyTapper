<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class StoreKitPseudonym
{
    private const ACCOUNT_CONTEXT = "pimpopom-storekit-account-v1\0";
    private const APP_TRANSACTION_CONTEXT = "pimpopom-storekit-app-transaction-v1\0";
    private const SPEND_CONTEXT = "pimpopom-storekit-spend-v1\0";

    public static function account(string $retentionKey, string $identifier): string
    {
        return self::digest($retentionKey, self::ACCOUNT_CONTEXT, $identifier);
    }

    public static function spend(string $retentionKey, string $identifier): string
    {
        return self::digest($retentionKey, self::SPEND_CONTEXT, $identifier);
    }

    public static function appTransaction(string $retentionKey, string $identifier): string
    {
        return self::digest($retentionKey, self::APP_TRANSACTION_CONTEXT, $identifier);
    }

    private static function digest(string $retentionKey, string $context, string $identifier): string
    {
        if (strlen($retentionKey) < 32 || $identifier === '') {
            throw new \InvalidArgumentException('StoreKit retention pseudonym input is invalid.');
        }

        return hash_hmac('sha256', $context . $identifier, $retentionKey, true);
    }
}
