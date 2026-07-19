<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class StoreKitProductCatalog
{
    private const REQUIRED_PRODUCTS = [
        'com.otcsoftware.pimpopom.coins.50.v1' => [
            'type' => 'consumable', 'coins' => 50, 'capability' => 'ad_free',
        ],
        'com.otcsoftware.pimpopom.coins.100.v1' => [
            'type' => 'consumable', 'coins' => 100, 'capability' => 'ad_free',
        ],
        'com.otcsoftware.pimpopom.coins.500.v1' => [
            'type' => 'consumable', 'coins' => 500, 'capability' => 'ad_free',
        ],
        'com.otcsoftware.pimpopom.coins.1000.v1' => [
            'type' => 'consumable', 'coins' => 1000, 'capability' => 'ad_free',
        ],
        'com.otcsoftware.pimpopom.removeads.lifetime' => [
            'type' => 'non_consumable', 'coins' => 0, 'capability' => 'ad_free',
        ],
    ];

    /** @var array<string, array{type: 'consumable'|'non_consumable', coins: int, capability: ?string}> */
    private array $products = [];

    public function __construct(array $configuration)
    {
        foreach ($configuration as $productId => $definition) {
            if (
                !is_string($productId)
                || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $productId) !== 1
                || !is_array($definition)
                || array_is_list($definition)
            ) {
                throw new ApiException(503, 'StoreKit product configuration is invalid.');
            }

            $type = $definition['type'] ?? null;
            $coins = $definition['coins'] ?? 0;
            $capability = $definition['capability'] ?? null;
            if ($type === 'consumable') {
                if (
                    !is_int($coins)
                    || $coins < 1
                    || $coins > 1_000_000
                    || ($capability !== null && $capability !== 'ad_free')
                ) {
                    throw new ApiException(503, 'StoreKit consumable configuration is invalid.');
                }
            } elseif ($type === 'non_consumable') {
                if ($coins !== 0 || !is_string($capability) || $capability !== 'ad_free') {
                    throw new ApiException(503, 'StoreKit entitlement configuration is invalid.');
                }
            } else {
                throw new ApiException(503, 'StoreKit product type is invalid.');
            }

            $unknown = array_diff(array_keys($definition), ['type', 'coins', 'capability']);
            if ($unknown !== []) {
                throw new ApiException(503, 'StoreKit product configuration contains unsupported fields.');
            }
            $this->products[$productId] = [
                'type' => $type,
                'coins' => $coins,
                'capability' => $capability,
            ];
        }

        // Allow the rest of the API to boot before StoreKit is configured.
        // Once any StoreKit product is supplied, fail closed unless the
        // complete, exact five-product allowlist is present.
        if ($this->products !== [] && $this->products != self::REQUIRED_PRODUCTS) {
            throw new ApiException(503, 'StoreKit must configure the exact PimPoPom product allowlist.');
        }
    }

    /** @return array{type: 'consumable'|'non_consumable', coins: int, capability: ?string} */
    public function require(string $productId): array
    {
        $product = $this->products[$productId] ?? null;
        if (!is_array($product)) {
            throw new ApiException(400, 'The signed App Store product is not available.');
        }
        return $product;
    }

    /** @return list<array{id: string, type: string, coins: int, capability: ?string}> */
    public function publicCatalog(): array
    {
        $catalog = [];
        foreach ($this->products as $id => $product) {
            $catalog[] = ['id' => $id, ...$product];
        }
        return $catalog;
    }

    public function isEmpty(): bool
    {
        return $this->products === [];
    }
}
