<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\AchievementService;
use SpeedyTapper\AccountDeletionService;
use SpeedyTapper\AppStoreNotificationService;
use SpeedyTapper\AppleJwsVerifier;
use SpeedyTapper\AppleCredentialRepository;
use SpeedyTapper\AppleSignInIdentityVerifier;
use SpeedyTapper\AppleSignInTokenClient;
use SpeedyTapper\App;
use SpeedyTapper\Config;
use SpeedyTapper\CoinWalletRepository;
use SpeedyTapper\Database;
use SpeedyTapper\DeploymentBootstrap;
use SpeedyTapper\GoogleClientIdentityVerifier;
use SpeedyTapper\GameCenterIdentityVerifier;
use SpeedyTapper\HttpRequest;
use SpeedyTapper\JsonResponse;
use SpeedyTapper\LeaderboardRepository;
use SpeedyTapper\LeaderboardModerationService;
use SpeedyTapper\PetShopService;
use SpeedyTapper\PlayerRepository;
use SpeedyTapper\PlayerIdentityService;
use SpeedyTapper\RunSubmissionService;
use SpeedyTapper\RunAttemptService;
use SpeedyTapper\RunProofValidator;
use SpeedyTapper\SessionStore;
use SpeedyTapper\SessionRegistry;
use SpeedyTapper\StoreKitAccountRepository;
use SpeedyTapper\StoreKitProductCatalog;
use SpeedyTapper\StoreKitService;
use SpeedyTapper\ThemeShopService;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/server/autoload.php';
$composerAutoload = $projectRoot . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}

try {
    $request = HttpRequest::fromGlobals();
    $config = Config::load($projectRoot);
    $database = Database::connect($config);
    $leaderboard = new LeaderboardRepository(
        $database,
        $config->seasonId,
        $config->seasonName,
    );
    DeploymentBootstrap::migrateIfMarked($database, $projectRoot, $leaderboard);
    $wallets = new CoinWalletRepository($database);
    $storeKitAccounts = new StoreKitAccountRepository(
        $database,
        $config->storeKitRetentionHmacKey ?? '',
    );
    $storeKitCatalog = new StoreKitProductCatalog($config->storeKitProducts);
    $appleJws = AppleJwsVerifier::fromPemFiles($config->storeKitRootCertificatePaths);
    $storeKit = new StoreKitService(
        $database,
        $config,
        $storeKitCatalog,
        $appleJws,
        $storeKitAccounts,
        $wallets,
    );
    $achievements = new AchievementService($database, $wallets);
    $pets = new PetShopService($database, $achievements, $wallets);
    $themes = new ThemeShopService($database, $wallets);
    $identities = new PlayerIdentityService($database);
    $appleIdentity = new AppleSignInIdentityVerifier([
        $config->appleSignInClientId
            ?? throw new ApiException(503, 'Apple sign-in audience is not configured.'),
    ], jwksCachePath: $config->appleSignInJwksCachePath);
    $appleTokens = null;
    $appleCredentials = null;
    if ($config->appleSignInIsConfigured()) {
        $appleTokens = new AppleSignInTokenClient(
            $config->appleSignInClientId ?? '',
            $config->appleSignInTeamId ?? '',
            $config->appleSignInKeyId ?? '',
            $config->appleSignInPrivateKeyPath ?? '',
        );
        $appleCredentials = new AppleCredentialRepository(
            $database,
            $config->appleSignInCredentialEncryptionKey ?? '',
        );
    }
    $app = new App(
        config: $config,
        players: new PlayerRepository($database, $pets, $themes),
        pets: $pets,
        themes: $themes,
        leaderboard: $leaderboard,
        attempts: new RunAttemptService($database),
        achievements: $achievements,
        runs: new RunSubmissionService(
            $database,
            $leaderboard,
            new RunProofValidator(),
            $achievements,
            $wallets,
        ),
        moderation: new LeaderboardModerationService($database),
        storeKitAccounts: $storeKitAccounts,
        storeKit: $storeKit,
        appStoreNotifications: new AppStoreNotificationService(
            $database,
            $config,
            $appleJws,
            $storeKit,
        ),
        accountDeletion: new AccountDeletionService(
            $database,
            $config->storeKitRetentionHmacKey ?? '',
        ),
        session: new SessionStore($request->isSecure(), new SessionRegistry($database)),
        identities: $identities,
        google: new GoogleClientIdentityVerifier($config->googleClientId),
        apple: $appleIdentity,
        appleTokens: $appleTokens,
        appleCredentials: $appleCredentials,
        gameCenter: new GameCenterIdentityVerifier(
            $config->storeKitBundleId,
            $config->gameCenterKeyHosts,
            $config->gameCenterTrustedRootCertificatePaths,
            $config->gameCenterUntrustedCertificateBundlePath
                ?? throw new ApiException(503, 'Game Center trust is not configured.'),
        ),
    );
    $app->dispatch($request);
} catch (ApiException $error) {
    JsonResponse::send($error->status, ['error' => $error->getMessage()], $error->headers);
} catch (Throwable $error) {
    error_log('SpeedyTapper API failed: ' . $error->getMessage());
    JsonResponse::send(503, ['error' => 'Service is temporarily unavailable.']);
}
