<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\App;
use SpeedyTapper\Config;
use SpeedyTapper\Database;
use SpeedyTapper\GoogleClientIdentityVerifier;
use SpeedyTapper\HttpRequest;
use SpeedyTapper\JsonResponse;
use SpeedyTapper\LeaderboardRepository;
use SpeedyTapper\MigrationRunner;
use SpeedyTapper\PetShopService;
use SpeedyTapper\PlayerRepository;
use SpeedyTapper\RunSubmissionService;
use SpeedyTapper\RunAttemptService;
use SpeedyTapper\RunProofValidator;
use SpeedyTapper\SessionStore;

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
    (new MigrationRunner($database, $projectRoot . '/server/migrations'))->run();
    $leaderboard = new LeaderboardRepository(
        $database,
        $config->seasonId,
        $config->seasonName,
    );
    $leaderboard->ensureSeason();
    $pets = new PetShopService($database);
    $app = new App(
        config: $config,
        players: new PlayerRepository($database, $pets),
        pets: $pets,
        leaderboard: $leaderboard,
        attempts: new RunAttemptService($database),
        runs: new RunSubmissionService($database, $leaderboard, new RunProofValidator()),
        session: new SessionStore($request->isSecure()),
        google: new GoogleClientIdentityVerifier($config->googleClientId),
    );
    $app->dispatch($request);
} catch (ApiException $error) {
    JsonResponse::send($error->status, ['error' => $error->getMessage()], $error->headers);
} catch (Throwable $error) {
    error_log('SpeedyTapper API failed: ' . $error->getMessage());
    JsonResponse::send(503, ['error' => 'Service is temporarily unavailable.']);
}
