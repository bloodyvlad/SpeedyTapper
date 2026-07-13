<?php

declare(strict_types=1);

namespace SpeedyTapper;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->databaseHost,
            $config->databasePort,
            $config->databaseName,
        );

        $database = new PDO($dsn, $config->databaseUser, $config->databasePassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $database->exec("SET time_zone = '+00:00'");
        return $database;
    }
}
