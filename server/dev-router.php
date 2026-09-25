<?php

declare(strict_types=1);

// Router for PHP's local development server. Production uses Apache and .htaccess.
$projectRoot = dirname(__DIR__);
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require $projectRoot . '/api/index.php';
    return true;
}

http_response_code(404);
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo '{"error":"Not found."}';
return true;
