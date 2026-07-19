<?php

declare(strict_types=1);

namespace SpeedyTapper;

final readonly class StoreKitTransaction
{
    public string $transactionId;
    public string $appleTransactionId;

    public function __construct(
        string $transactionId,
        public string $originalTransactionId,
        public ?string $appTransactionId,
        public string $productId,
        public string $productType,
        public string $environment,
        public string $bundleId,
        public ?string $appAccountToken,
        public string $ownershipType,
        public int $quantity,
        public int $purchaseDateMs,
        public int $signedDateMs,
        public ?int $revocationDateMs,
        public ?int $revocationReason,
        public array $catalogProduct,
    ) {
        $this->appleTransactionId = $transactionId;
        $this->transactionId = self::storageIdFor($environment, $transactionId);
    }

    public static function fromVerifiedPayload(
        array $payload,
        Config $config,
        StoreKitProductCatalog $catalog,
        ?string $expectedAppAccountToken,
    ): self {
        if ($config->acceptedStoreKitEnvironments() === []) {
            throw new ApiException(503, 'StoreKit is not configured.');
        }
        $transactionId = self::identifier($payload, 'transactionId');
        $originalTransactionId = self::identifier($payload, 'originalTransactionId');
        $appTransactionId = self::optionalIdentifier($payload, 'appTransactionId');
        $productId = self::productIdentifier($payload);
        $product = $catalog->require($productId);

        $signedType = $payload['type'] ?? null;
        $expectedType = match ($product['type']) {
            'consumable' => 'Consumable',
            'non_consumable' => 'Non-Consumable',
        };
        if (!is_string($signedType) || !hash_equals($expectedType, $signedType)) {
            throw new ApiException(400, 'The signed App Store product type is invalid.');
        }
        $ownershipType = $payload['inAppOwnershipType'] ?? null;
        if (!is_string($ownershipType) || !in_array($ownershipType, ['PURCHASED', 'FAMILY_SHARED'], true)) {
            throw new ApiException(400, 'The signed App Store ownership type is invalid.');
        }
        if ($product['type'] === 'consumable' && $ownershipType !== 'PURCHASED') {
            throw new ApiException(400, 'Consumable coin packs cannot be Family Shared.');
        }
        if ($ownershipType === 'FAMILY_SHARED' && $appTransactionId === null) {
            throw new ApiException(
                400,
                'A Family Shared entitlement requires Apple app-transaction identity.',
            );
        }

        $bundleId = $payload['bundleId'] ?? null;
        $environment = $payload['environment'] ?? null;
        if (!is_string($bundleId) || !hash_equals($config->storeKitBundleId, $bundleId)) {
            throw new ApiException(400, 'The signed App Store bundle does not match PimPoPom.');
        }
        if (!is_string($environment) || !$config->acceptsStoreKitEnvironment($environment)) {
            throw new ApiException(400, 'The signed App Store environment is not accepted here.');
        }

        $signedToken = $payload['appAccountToken'] ?? null;
        $normalizedExpectedToken = $expectedAppAccountToken === null
            ? null
            : strtolower(trim($expectedAppAccountToken));
        $normalizedSignedToken = is_string($signedToken) ? strtolower(trim($signedToken)) : '';
        if ($normalizedExpectedToken !== null && !Uuid::isValidV4($normalizedExpectedToken)) {
            throw new ApiException(409, 'The PimPoPom StoreKit binding is invalid.');
        }
        if ($ownershipType === 'PURCHASED') {
            if (
                !Uuid::isValidV4($normalizedSignedToken)
                || ($normalizedExpectedToken !== null
                    && !hash_equals($normalizedExpectedToken, $normalizedSignedToken))
            ) {
                throw new ApiException(409, 'The App Store purchase belongs to a different PimPoPom account.');
            }
        } elseif ($normalizedSignedToken !== '' && !Uuid::isValidV4($normalizedSignedToken)) {
            // Apple doesn't support assigning an appAccountToken to a
            // FAMILY_SHARED transaction. If a signed token is present it can
            // belong to the purchaser, not the beneficiary, so the recipient
            // is bound through Apple's per-family-member appTransactionId.
            throw new ApiException(409, 'The Family Shared App Store account token is invalid.');
        }

        $quantity = self::positiveInteger($payload, 'quantity', 100);
        if ($quantity !== 1) {
            throw new ApiException(400, 'Each PimPoPom StoreKit transaction must contain one product unit.');
        }
        $purchaseDateMs = self::positiveInteger($payload, 'purchaseDate', PHP_INT_MAX);
        $signedDateMs = self::positiveInteger($payload, 'signedDate', PHP_INT_MAX);
        $revocationDateMs = self::optionalPositiveInteger($payload, 'revocationDate', PHP_INT_MAX);
        $revocationReason = self::optionalPositiveInteger($payload, 'revocationReason', 10, true);

        return new self(
            transactionId: $transactionId,
            originalTransactionId: $originalTransactionId,
            appTransactionId: $appTransactionId,
            productId: $productId,
            productType: $product['type'],
            environment: $environment,
            bundleId: $bundleId,
            appAccountToken: $normalizedSignedToken === '' ? null : $normalizedSignedToken,
            ownershipType: $ownershipType,
            quantity: $quantity,
            purchaseDateMs: $purchaseDateMs,
            signedDateMs: $signedDateMs,
            revocationDateMs: $revocationDateMs,
            revocationReason: $revocationReason,
            catalogProduct: $product,
        );
    }

    public function grossCoins(): int
    {
        return (int) $this->catalogProduct['coins'];
    }

    public function storageId(): string
    {
        return $this->transactionId;
    }

    public static function storageIdFor(string $environment, string $transactionId): string
    {
        if (!in_array($environment, ['Sandbox', 'Production'], true)
            || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $transactionId) !== 1
        ) {
            throw new \InvalidArgumentException('StoreKit storage identity is invalid.');
        }
        return $environment . ':' . $transactionId;
    }

    public static function appleIdFromStorage(string $storageId): string
    {
        foreach (['Sandbox:', 'Production:'] as $prefix) {
            if (str_starts_with($storageId, $prefix)) {
                return substr($storageId, strlen($prefix));
            }
        }
        return $storageId;
    }

    private static function identifier(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $value) !== 1) {
            throw new ApiException(400, 'The signed App Store transaction identifier is invalid.');
        }
        return $value;
    }

    private static function optionalIdentifier(array $payload, string $field): ?string
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) return null;
        return self::identifier($payload, $field);
    }

    private static function productIdentifier(array $payload): string
    {
        $value = $payload['productId'] ?? null;
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $value) !== 1) {
            throw new ApiException(400, 'The signed App Store product identifier is invalid.');
        }
        return $value;
    }

    private static function positiveInteger(array $payload, string $field, int $maximum): int
    {
        $value = $payload[$field] ?? null;
        if (!is_int($value) || $value < 1 || $value > $maximum) {
            throw new ApiException(400, 'The signed App Store ' . $field . ' is invalid.');
        }
        return $value;
    }

    private static function optionalPositiveInteger(
        array $payload,
        string $field,
        int $maximum,
        bool $allowZero = false,
    ): ?int {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) return null;
        $value = $payload[$field];
        $minimum = $allowZero ? 0 : 1;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ApiException(400, 'The signed App Store ' . $field . ' is invalid.');
        }
        return $value;
    }
}
