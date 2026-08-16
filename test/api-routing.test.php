<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$router = $root . '/server/dev-router.php';
$htaccessPath = $root . '/.htaccess';
$failures = [];
$server = null;
$pipes = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$request = static function (int $port, string $path): array {
    $headers = [];
    $handle = curl_init("http://127.0.0.1:{$port}{$path}");
    if ($handle === false) {
        throw new RuntimeException('Could not initialize an HTTP request.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
            $headers[] = trim($header);
            return strlen($header);
        },
    ]);
    $body = curl_exec($handle);
    if ($body === false) {
        $message = curl_error($handle);
        throw new RuntimeException("HTTP request failed: {$message}");
    }
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    return [
        'status' => is_int($status) && $status > 0 ? $status : null,
        'headers' => $headers,
        'body' => is_string($body) ? $body : '',
    ];
};

try {
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    if ($socket === false) {
        throw new RuntimeException("Could not reserve a loopback port: {$socketMessage} ({$socketError}).");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address) || preg_match('/:(\d+)$/', $address, $matches) !== 1) {
        throw new RuntimeException('Could not determine the reserved loopback port.');
    }
    $port = (int) $matches[1];

    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root, $router],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start the PHP development server.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.05);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        $status = proc_get_status($server);
        if (!$status['running']) {
            break;
        }
        usleep(20_000);
    }
    if (!$ready) {
        $diagnostic = trim(stream_get_contents($pipes[2]));
        throw new RuntimeException('PHP development server did not start.' . ($diagnostic === '' ? '' : " {$diagnostic}"));
    }

    foreach (['/README.md', '/server/config.local.example.php', '/'] as $path) {
        $response = $request($port, $path);
        $assert($response['status'] === 404, "GET {$path} must return 404; received " . var_export($response['status'], true) . '.');
    }

    $health = $request($port, '/api/health');
    $contentType = implode("\n", array_filter(
        $health['headers'],
        static fn (string $header): bool => str_starts_with(strtolower($header), 'content-type:'),
    ));
    $assert($health['status'] !== null && $health['status'] !== 404, 'GET /api/health must dispatch to the API boundary.');
    $assert(str_contains(strtolower($contentType), 'application/json'), 'GET /api/health must return an API JSON response.');
    $assert(json_decode($health['body'], true) !== null, 'GET /api/health must return valid JSON.');

    $htaccess = file_get_contents($htaccessPath);
    if (!is_string($htaccess)) {
        throw new RuntimeException('.htaccess is not readable.');
    }
    $assert(
        preg_match('/RewriteRule\s+\^api\(\?:\/\.\*\)\?\$\s+api\/index\.php/', $htaccess) === 1,
        '.htaccess must rewrite /api and /api/* to api/index.php.',
    );
    $assert(
        preg_match('/RewriteRule\s+\^\s+-\s+\[R=404,L\]/', $htaccess) === 1,
        '.htaccess must return 404 for every non-API path by default.',
    );
    $assert(!str_contains($htaccess, 'AddType'), '.htaccess must not contain browser audio MIME policy.');
    $assert(!str_contains($htaccess, 'Content-Security-Policy'), '.htaccess must not contain browser CSP policy.');
    $assert(
        preg_match('/service-worker|manifest|sw\.js/i', $htaccess) !== 1,
        '.htaccess must not contain PWA-specific policy.',
    );
} catch (Throwable $error) {
    $failures[] = $error->getMessage();
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if (!proc_get_status($server)['running']) {
                break;
            }
            usleep(10_000);
        }
        if (proc_get_status($server)['running']) {
            proc_terminate($server, 9);
        }
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($server)) {
        proc_close($server);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "API routing checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "API routing checks passed.\n");
