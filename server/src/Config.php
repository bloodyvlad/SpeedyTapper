<?php

declare(strict_types=1);

namespace SpeedyTapper;

final readonly class Config
{
    public function __construct(
        public string $databaseHost,
        public int $databasePort,
        public string $databaseName,
        public string $databaseUser,
        public string $databasePassword,
        public string $googleClientId,
        public string $seasonId,
        public string $seasonName,
        public string $storeKitBundleId = 'com.otcsoftware.pimpopom',
        public ?string $storeKitEnvironment = null,
        public ?string $storeKitAppAppleId = null,
        public array $storeKitProducts = [],
        public ?string $storeKitRetentionHmacKey = null,
        public array $storeKitRootCertificatePaths = [],
        public ?string $storeKitIssuerId = null,
        public ?string $storeKitKeyId = null,
        public ?string $storeKitPrivateKeyPath = null,
        public array $storeKitEnvironments = [],
        public ?string $appleSignInClientId = null,
        public ?string $appleSignInTeamId = null,
        public ?string $appleSignInKeyId = null,
        public ?string $appleSignInPrivateKeyPath = null,
        public ?string $appleSignInCredentialEncryptionKey = null,
        public ?string $appleSignInJwksCachePath = null,
        public array $gameCenterKeyHosts = ['static.gc.apple.com'],
        public array $gameCenterTrustedRootCertificatePaths = [],
        public ?string $gameCenterUntrustedCertificateBundlePath = null,
    ) {
    }

    public static function load(string $projectRoot): self
    {
        $explicitPath = getenv('SPEEDYTAPPER_CONFIG_PATH');
        $home = getenv('HOME');
        if (($home === false || trim($home) === '') && isset($_SERVER['HOME'])) {
            $home = (string) $_SERVER['HOME'];
        }
        if (($home === false || trim((string) $home) === '') && isset($_SERVER['DOCUMENT_ROOT'])) {
            $documentRoot = str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']);
            if (preg_match('#^(/home/[^/]+)/domains/[^/]+/public_html(?:/|$)#', $documentRoot, $matches)) {
                $home = $matches[1];
            }
        }
        $candidatePaths = [];
        if (is_string($explicitPath) && trim($explicitPath) !== '') {
            $candidatePaths[] = trim($explicitPath);
        }
        if (is_string($home) && trim($home) !== '') {
            $candidatePaths[] = rtrim(trim($home), '/') . '/.config/speedytapper/config.php';
        }
        // This ignored fallback is used locally and by a curated MCP release artifact.
        // A private home file or explicit path remains preferred for production.
        $candidatePaths[] = $projectRoot . '/server/config.local.php';

        $local = [];
        foreach ($candidatePaths as $candidatePath) {
            if (!is_file($candidatePath)) {
                continue;
            }
            $local = require $candidatePath;
            break;
        }
        if (!is_array($local)) {
            throw new ApiException(503, 'Server configuration is invalid.');
        }

        $value = static function (string $key, ?string $default = null) use ($local): string {
            $environmentValue = getenv($key);
            $candidate = $environmentValue !== false ? $environmentValue : ($local[$key] ?? $default);
            if (!is_string($candidate) || trim($candidate) === '') {
                throw new ApiException(503, 'Server configuration is incomplete.');
            }
            return trim($candidate);
        };
        $optional = static function (string $key) use ($local): ?string {
            $environmentValue = getenv($key);
            $candidate = $environmentValue !== false ? $environmentValue : ($local[$key] ?? null);
            if (!is_string($candidate) || trim($candidate) === '') {
                return null;
            }
            return trim($candidate);
        };

        $port = filter_var($value('SPEEDYTAPPER_DB_PORT', '3306'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new ApiException(503, 'Server configuration is invalid.');
        }

        $seasonId = $value('SPEEDYTAPPER_SEASON_ID', 'season-1');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $seasonId)) {
            throw new ApiException(503, 'Server configuration is invalid.');
        }
        $seasonName = $value('SPEEDYTAPPER_SEASON_NAME', 'Season 1');
        if (mb_strlen($seasonName, 'UTF-8') > 80) {
            throw new ApiException(503, 'Server configuration is invalid.');
        }

        $storeKitBundleId = $optional('SPEEDYTAPPER_STOREKIT_BUNDLE_ID')
            ?? 'com.otcsoftware.pimpopom';
        if (!hash_equals('com.otcsoftware.pimpopom', $storeKitBundleId)) {
            throw new ApiException(503, 'StoreKit bundle configuration is invalid.');
        }
        $storeKitEnvironment = $optional('SPEEDYTAPPER_STOREKIT_ENVIRONMENT');
        $configuredEnvironments = $local['SPEEDYTAPPER_STOREKIT_ENVIRONMENTS'] ?? null;
        $environmentsValue = getenv('SPEEDYTAPPER_STOREKIT_ENVIRONMENTS');
        if ($environmentsValue !== false) {
            $configuredEnvironments = explode(',', $environmentsValue);
        }
        if ($configuredEnvironments === null && $storeKitEnvironment !== null) {
            $configuredEnvironments = [$storeKitEnvironment];
        }
        if (is_string($configuredEnvironments)) {
            $configuredEnvironments = explode(',', $configuredEnvironments);
        }
        if ($configuredEnvironments === null) {
            $configuredEnvironments = [];
        }
        if (!is_array($configuredEnvironments)) {
            throw new ApiException(503, 'StoreKit environment configuration is invalid.');
        }
        $storeKitEnvironments = array_values(array_unique(array_map(
            static function (mixed $environment): string {
                if (!is_string($environment)
                    || !in_array(trim($environment), ['Sandbox', 'Production'], true)
                ) {
                    throw new ApiException(503, 'StoreKit environment configuration is invalid.');
                }
                return trim($environment);
            },
            $configuredEnvironments,
        )));
        if ($storeKitEnvironment !== null && !in_array($storeKitEnvironment, $storeKitEnvironments, true)) {
            throw new ApiException(503, 'StoreKit environment configuration is invalid.');
        }
        // Keep the singular property as a compatibility/default value for
        // older configuration consumers. Runtime verification always uses
        // the explicit accepted-environment list.
        $storeKitEnvironment ??= $storeKitEnvironments[0] ?? null;
        $storeKitAppAppleId = $optional('SPEEDYTAPPER_STOREKIT_APP_APPLE_ID');
        if ($storeKitAppAppleId !== null && preg_match('/^[1-9][0-9]{4,19}$/D', $storeKitAppAppleId) !== 1) {
            throw new ApiException(503, 'StoreKit app identifier configuration is invalid.');
        }

        $appleSignInClientId = $optional('SPEEDYTAPPER_APPLE_SIGNIN_CLIENT_ID')
            ?? $storeKitBundleId;
        if (
            strlen($appleSignInClientId) > 255
            || preg_match('/^[A-Za-z0-9.-]+$/D', $appleSignInClientId) !== 1
        ) {
            throw new ApiException(503, 'Apple Sign in client configuration is invalid.');
        }
        $appleSignInTeamId = $optional('SPEEDYTAPPER_APPLE_SIGNIN_TEAM_ID');
        $appleSignInKeyId = $optional('SPEEDYTAPPER_APPLE_SIGNIN_KEY_ID');
        $appleSignInPrivateKeyPath = $optional('SPEEDYTAPPER_APPLE_SIGNIN_PRIVATE_KEY_PATH');
        $appleSignInCredentialEncryptionKey = $optional(
            'SPEEDYTAPPER_APPLE_SIGNIN_CREDENTIAL_ENCRYPTION_KEY'
        );
        $appleSignInJwksCachePath = $optional('SPEEDYTAPPER_APPLE_SIGNIN_JWKS_CACHE_PATH');
        if ($appleSignInJwksCachePath === null && is_string($home) && trim($home) !== '') {
            $appleSignInJwksCachePath = rtrim(trim($home), '/')
                . '/.cache/speedytapper/apple-signin-jwks.json';
        }
        if ($appleSignInJwksCachePath !== null && (
            !str_starts_with($appleSignInJwksCachePath, '/')
            || str_contains($appleSignInJwksCachePath, "\0")
        )) {
            throw new ApiException(503, 'Apple Sign in cache configuration is invalid.');
        }

        $configuredGameCenterHosts = $local['SPEEDYTAPPER_GAME_CENTER_KEY_HOSTS']
            ?? ['static.gc.apple.com'];
        $gameCenterHostsEnvironment = getenv('SPEEDYTAPPER_GAME_CENTER_KEY_HOSTS');
        if ($gameCenterHostsEnvironment !== false) {
            $configuredGameCenterHosts = explode(',', $gameCenterHostsEnvironment);
        }
        if (is_string($configuredGameCenterHosts)) {
            $configuredGameCenterHosts = explode(',', $configuredGameCenterHosts);
        }
        if (!is_array($configuredGameCenterHosts)) {
            throw new ApiException(503, 'Game Center key-host configuration is invalid.');
        }
        $gameCenterKeyHosts = array_values(array_unique(array_map(
            static function (mixed $host): string {
                if (!is_string($host)) {
                    throw new ApiException(503, 'Game Center key-host configuration is invalid.');
                }
                $host = strtolower(trim($host));
                if (preg_match('/^[a-z0-9.-]{1,253}$/D', $host) !== 1) {
                    throw new ApiException(503, 'Game Center key-host configuration is invalid.');
                }
                return $host;
            },
            $configuredGameCenterHosts,
        )));
        if ($gameCenterKeyHosts === []) {
            throw new ApiException(503, 'Game Center key-host configuration is invalid.');
        }

        $configuredGameCenterRootPaths = $local['SPEEDYTAPPER_GAME_CENTER_ROOT_CERTIFICATE_PATHS']
            ?? [$projectRoot . '/server/certs/DigiCertTrustedRootG4.pem'];
        $gameCenterRootsEnvironment = getenv('SPEEDYTAPPER_GAME_CENTER_ROOT_CERTIFICATE_PATHS');
        if ($gameCenterRootsEnvironment !== false) {
            $configuredGameCenterRootPaths = explode(',', $gameCenterRootsEnvironment);
        }
        if (is_string($configuredGameCenterRootPaths)) {
            $configuredGameCenterRootPaths = explode(',', $configuredGameCenterRootPaths);
        }
        if (!is_array($configuredGameCenterRootPaths)) {
            throw new ApiException(503, 'Game Center trust configuration is invalid.');
        }
        $gameCenterTrustedRootCertificatePaths = array_values(array_filter(array_map(
            static function (mixed $path): string {
                if (!is_string($path)) {
                    throw new ApiException(503, 'Game Center trust configuration is invalid.');
                }
                return trim($path);
            },
            $configuredGameCenterRootPaths,
        ), static fn (string $path): bool => $path !== ''));
        if ($gameCenterTrustedRootCertificatePaths === []) {
            throw new ApiException(503, 'Game Center trust configuration is invalid.');
        }
        $gameCenterUntrustedCertificateBundlePath = $optional(
            'SPEEDYTAPPER_GAME_CENTER_INTERMEDIATE_CERTIFICATE_BUNDLE_PATH'
        ) ?? $projectRoot
            . '/server/certs/DigiCertTrustedG4CodeSigningRSA4096SHA3842021CA1.pem';

        $productsJson = $optional('SPEEDYTAPPER_STOREKIT_PRODUCTS_JSON');
        $storeKitProducts = [];
        if ($productsJson !== null) {
            try {
                $decodedProducts = json_decode($productsJson, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ApiException(503, 'StoreKit product configuration is invalid.');
            }
            if (!is_array($decodedProducts) || array_is_list($decodedProducts)) {
                throw new ApiException(503, 'StoreKit product configuration is invalid.');
            }
            $storeKitProducts = $decodedProducts;
        }

        $rootCertificatePaths = $local['SPEEDYTAPPER_STOREKIT_ROOT_CERTIFICATE_PATHS'] ?? null;
        $rootPathsEnvironment = getenv('SPEEDYTAPPER_STOREKIT_ROOT_CERTIFICATE_PATHS');
        if ($rootPathsEnvironment !== false) {
            $rootCertificatePaths = array_values(array_filter(array_map(
                'trim',
                explode(',', $rootPathsEnvironment),
            )));
        }
        if ($rootCertificatePaths === null) {
            $rootCertificatePaths = [
                $projectRoot . '/server/certs/AppleRootCA-G2.pem',
                $projectRoot . '/server/certs/AppleRootCA-G3.pem',
            ];
        }
        if (!is_array($rootCertificatePaths) || array_is_list($rootCertificatePaths) === false) {
            throw new ApiException(503, 'StoreKit trust-root configuration is invalid.');
        }
        $rootCertificatePaths = array_values(array_map(static function (mixed $path): string {
            if (!is_string($path) || trim($path) === '') {
                throw new ApiException(503, 'StoreKit trust-root configuration is invalid.');
            }
            return trim($path);
        }, $rootCertificatePaths));

        return new self(
            databaseHost: $value('SPEEDYTAPPER_DB_HOST', 'localhost'),
            databasePort: $port,
            databaseName: $value('SPEEDYTAPPER_DB_NAME'),
            databaseUser: $value('SPEEDYTAPPER_DB_USER'),
            databasePassword: $value('SPEEDYTAPPER_DB_PASSWORD'),
            googleClientId: $value('SPEEDYTAPPER_GOOGLE_CLIENT_ID'),
            seasonId: $seasonId,
            seasonName: $seasonName,
            storeKitBundleId: $storeKitBundleId,
            storeKitEnvironment: $storeKitEnvironment,
            storeKitAppAppleId: $storeKitAppAppleId,
            storeKitProducts: $storeKitProducts,
            storeKitRetentionHmacKey: $optional('SPEEDYTAPPER_STOREKIT_RETENTION_HMAC_KEY'),
            storeKitRootCertificatePaths: $rootCertificatePaths,
            storeKitIssuerId: $optional('SPEEDYTAPPER_STOREKIT_ISSUER_ID'),
            storeKitKeyId: $optional('SPEEDYTAPPER_STOREKIT_KEY_ID'),
            storeKitPrivateKeyPath: $optional('SPEEDYTAPPER_STOREKIT_PRIVATE_KEY_PATH'),
            storeKitEnvironments: $storeKitEnvironments,
            appleSignInClientId: $appleSignInClientId,
            appleSignInTeamId: $appleSignInTeamId,
            appleSignInKeyId: $appleSignInKeyId,
            appleSignInPrivateKeyPath: $appleSignInPrivateKeyPath,
            appleSignInCredentialEncryptionKey: $appleSignInCredentialEncryptionKey,
            appleSignInJwksCachePath: $appleSignInJwksCachePath,
            gameCenterKeyHosts: $gameCenterKeyHosts,
            gameCenterTrustedRootCertificatePaths: $gameCenterTrustedRootCertificatePaths,
            gameCenterUntrustedCertificateBundlePath: $gameCenterUntrustedCertificateBundlePath,
        );
    }

    /** @return list<string> */
    public function acceptedStoreKitEnvironments(): array
    {
        if ($this->storeKitEnvironments !== []) {
            return array_values($this->storeKitEnvironments);
        }
        return $this->storeKitEnvironment === null ? [] : [$this->storeKitEnvironment];
    }

    public function acceptsStoreKitEnvironment(string $environment): bool
    {
        return in_array($environment, $this->acceptedStoreKitEnvironments(), true);
    }

    public function storeKitIsConfigured(): bool
    {
        $environments = $this->acceptedStoreKitEnvironments();
        return $environments !== []
            && (!in_array('Production', $environments, true) || $this->storeKitAppAppleId !== null)
            && $this->storeKitProducts !== []
            && $this->storeKitRetentionHmacKey !== null
            && strlen($this->storeKitRetentionHmacKey) >= 32
            && $this->storeKitRootCertificatePaths !== [];
    }

    public function storeKitServerApiIsConfigured(): bool
    {
        return $this->storeKitIsConfigured()
            && $this->storeKitIssuerId !== null
            && $this->storeKitKeyId !== null
            && $this->storeKitPrivateKeyPath !== null;
    }

    public function appleSignInIsConfigured(): bool
    {
        return $this->appleSignInClientId !== null
            && $this->appleSignInTeamId !== null
            && preg_match('/^[A-Z0-9]{10}$/D', $this->appleSignInTeamId) === 1
            && $this->appleSignInKeyId !== null
            && preg_match('/^[A-Z0-9]{10}$/D', $this->appleSignInKeyId) === 1
            && $this->appleSignInPrivateKeyPath !== null
            && is_readable($this->appleSignInPrivateKeyPath)
            && $this->appleSignInCredentialEncryptionKey !== null
            && strlen($this->appleSignInCredentialEncryptionKey) >= 32
            && ($this->storeKitKeyId === null
                || !hash_equals($this->storeKitKeyId, $this->appleSignInKeyId));
    }
}
