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
use SpeedyTapper\GameCenterPublicationRepository;
use SpeedyTapper\HttpRequest;
use SpeedyTapper\JsonResponse;
use SpeedyTapper\LeaderboardRepository;
use SpeedyTapper\LeaderboardModerationService;
use SpeedyTapper\MultiplayerLeaderboardRepository;
use SpeedyTapper\MultiplayerMatchService;
use SpeedyTapper\MultiplayerProofValidator;
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

// Release operators may inject this untracked marker into a deployment
// artifact while a destructive, separately-invoked database migration runs.
// Check it before parsing requests, opening sessions, or connecting to MySQL so
// no API write can race the reset. The marker is never part of a Git commit.
if (is_file($projectRoot . '/server/.maintenance')) {
    JsonResponse::send(
        503,
        ['error' => 'PimPoPom is briefly unavailable for maintenance.'],
        ['Retry-After' => '60'],
    );
    return;
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
    $multiplayerLeaderboard = new MultiplayerLeaderboardRepository(
        $database,
        $config->seasonId,
        $config->seasonName,
    );
    DeploymentBootstrap::migrateIfMarked($database, $projectRoot, $leaderboard);
    $wallets = new CoinWalletRepository($database);
    $gameCenterPublication = null;
    if ($config->gameCenterPublicationStorageIsConfigured()) {
        $gameCenterPublication = new GameCenterPublicationRepository(
            $database,
            $config->gameCenterPlayerIdEncryptionKey ?? '',
            $config->gameCenterPreReleased
                ?? throw new ApiException(503, 'Game Center publication lane is not configured.'),
        );
    }
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
    $achievements = new AchievementService(
        $database,
        $wallets,
        $gameCenterPublication,
    );
    $pets = new PetShopService($database, $achievements, $wallets);
    $themes = new ThemeShopService($database, $wallets);
    $identities = new PlayerIdentityService($database, $gameCenterPublication);
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
            $gameCenterPublication,
        ),
        moderation: new LeaderboardModerationService(
            $database,
            $gameCenterPublication,
            $achievements,
        ),
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
            $gameCenterPublication,
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
        gameCenterPublication: $gameCenterPublication,
        multiplayer: new MultiplayerMatchService(
            $database,
            $config->seasonId,
            $config->seasonName,
            new MultiplayerProofValidator(),
            $gameCenterPublication,
        ),
        multiplayerLeaderboard: $multiplayerLeaderboard,
    );
    $app->dispatch($request);
} catch (ApiException $error) {
    JsonResponse::send($error->status, ['error' => $error->getMessage()], $error->headers);
} catch (Throwable $error) {
    error_log('SpeedyTapper API failed: ' . $error->getMessage());
    JsonResponse::send(503, ['error' => 'Service is temporarily unavailable.']);
}
