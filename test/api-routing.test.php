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

$stopServer = static function (&$server, array &$pipes): void {
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
    $server = null;
    $pipes = [];
};

try {
    $ready = false;
    $startupDiagnostics = [];
    for ($bindAttempt = 0; $bindAttempt < 5 && !$ready; $bindAttempt++) {
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
        unset($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

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
            $startupDiagnostics[] = trim(stream_get_contents($pipes[2]));
            $stopServer($server, $pipes);
        }
    }
    if (!$ready) {
        $diagnostic = trim(implode(' ', array_filter($startupDiagnostics)));
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
    $htaccessPolicy = preg_replace('/^[ \t]*#.*$/m', '', $htaccess);
    if (!is_string($htaccessPolicy)) {
        throw new RuntimeException('.htaccess comments could not be normalized.');
    }
    $assert(
        preg_match('/RewriteRule\s+\^api\(\?:\/\.\*\)\?\$\s+api\/index\.php/', $htaccessPolicy) === 1,
        '.htaccess must rewrite /api and /api/* to api/index.php.',
    );
    $assert(
        preg_match('/RewriteRule\s+\^\s+-\s+\[R=404,L\]/', $htaccessPolicy) === 1,
        '.htaccess must return 404 for every non-API path by default.',
    );

    $rewriteRules = [];
    preg_match_all(
        '/^\s*RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]+)\]/mi',
        $htaccessPolicy,
        $rewriteRules,
        PREG_SET_ORDER,
    );
    $sensitiveRewrite = null;
    foreach ($rewriteRules as $rewriteRule) {
        $flags = array_map('strtoupper', array_map('trim', explode(',', $rewriteRule[3])));
        if ($rewriteRule[2] === '-' && in_array('F', $flags, true)) {
            $sensitiveRewrite = $rewriteRule[1];
            break;
        }
    }
    foreach (['.git/config', 'server/config.local.php', 'vendor/autoload.php'] as $path) {
        $assert(
            is_string($sensitiveRewrite) && preg_match('#' . $sensitiveRewrite . '#i', $path) === 1,
            ".htaccess must forbid {$path} through an active rewrite rule.",
        );
    }

    $filesMatch = [];
    $hasFilesMatch = preg_match(
        '/<FilesMatch\s+"([^"]+)">(.*?)<\/FilesMatch>/is',
        $htaccessPolicy,
        $filesMatch,
    ) === 1;
    $assert(
        $hasFilesMatch && preg_match('/Require\s+all\s+denied/i', $filesMatch[2] ?? '') === 1,
        '.htaccess must actively deny files matched by its sensitive-file policy.',
    );
    foreach (['.env', '.env.production', 'config.php', 'config.local.example.php', 'private.key', 'apple.p8', 'root.pem'] as $file) {
        $assert(
            $hasFilesMatch && preg_match('#' . ($filesMatch[1] ?? '(?!)') . '#', $file) === 1,
            ".htaccess sensitive-file policy must match {$file}.",
        );
    }

    $assert(!str_contains($htaccessPolicy, 'AddType'), '.htaccess must not contain browser audio MIME policy.');
    $assert(!str_contains($htaccessPolicy, 'Content-Security-Policy'), '.htaccess must not contain browser CSP policy.');
    $assert(
        preg_match('/service-worker|manifest|sw\.js/i', $htaccessPolicy) !== 1,
        '.htaccess must not contain PWA-specific policy.',
    );
} catch (Throwable $error) {
    $failures[] = $error->getMessage();
} finally {
    $stopServer($server, $pipes);
}

if ($failures !== []) {
    fwrite(STDERR, "API routing checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "API routing checks passed.\n");
