<?php

declare(strict_types=1);

// Router for PHP's local development server. Production uses Apache and .htaccess.
$projectRoot = dirname(__DIR__);
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

if (str_starts_with($path, '/api/')) {
    require $projectRoot . '/api/index.php';
}

if (
    preg_match('#^/(?:server|vendor|\.git)(?:/|$)#i', $path)
    || preg_match('#^/api/.*\.js$#i', $path)
    || preg_match('#/(?:\.env[^/]*|composer\.(?:json|lock))$#i', $path)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    return true;
}

if ($path === '/') {
    $path = '/index.html';
}

$candidate = realpath($projectRoot . $path);
if ($candidate === false || !str_starts_with($candidate, $projectRoot . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return true;
}

return false;
