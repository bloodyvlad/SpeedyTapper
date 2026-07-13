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
        // This ignored in-project fallback is for local development only. Production uses
        // SPEEDYTAPPER_CONFIG_PATH or the private file under the hosting account home.
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
