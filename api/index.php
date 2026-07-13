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
use SpeedyTapper\PlayerRepository;
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
    $leaderboard = new LeaderboardRepository(
        $database,
        $config->seasonId,
        $config->seasonName,
    );
    $leaderboard->ensureSeason();
    $app = new App(
        config: $config,
        players: new PlayerRepository($database),
        leaderboard: $leaderboard,
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
