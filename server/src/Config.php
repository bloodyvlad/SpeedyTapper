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
    ) {
    }

    public static function load(string $projectRoot): self
    {
        $localPath = $projectRoot . '/server/config.local.php';
        $local = is_file($localPath) ? require $localPath : [];
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

        return new self(
            databaseHost: $value('SPEEDYTAPPER_DB_HOST', 'localhost'),
            databasePort: $port,
            databaseName: $value('SPEEDYTAPPER_DB_NAME'),
            databaseUser: $value('SPEEDYTAPPER_DB_USER'),
            databasePassword: $value('SPEEDYTAPPER_DB_PASSWORD'),
            googleClientId: $value('SPEEDYTAPPER_GOOGLE_CLIENT_ID'),
            seasonId: $seasonId,
            seasonName: $seasonName,
        );
    }
}
