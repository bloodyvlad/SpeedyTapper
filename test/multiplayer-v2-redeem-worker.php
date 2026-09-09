<?php

declare(strict_types=1);

// Disposable MariaDB harness worker. Input/output contain no logged credentials.
use SpeedyTapper\ApiException;
use SpeedyTapper\Config;
use SpeedyTapper\MultiplayerV2Service;

require dirname(__DIR__) . '/server/autoload.php';
$dsn = getenv('SPEEDYTAPPER_TEST_MARIADB_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:host=127.0.0.1;')) {
    throw new RuntimeException('A disposable loopback MariaDB DSN is required.');
}
$database = new PDO($dsn, getenv('SPEEDYTAPPER_TEST_MARIADB_USER'), getenv('SPEEDYTAPPER_TEST_MARIADB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database->exec("SET time_zone = '+00:00'");
$input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
$service = new MultiplayerV2Service($database, new Config('', 0, '', '', '', '', '', '',
    realtimeUrl: 'ws://127.0.0.1:8080/socket', multiplayerServiceSecret: str_repeat('s', 64)));
try {
    $service->redeem($input);
    fwrite(STDOUT, '200');
} catch (ApiException $error) {
    fwrite(STDOUT, (string) $error->status);
}
