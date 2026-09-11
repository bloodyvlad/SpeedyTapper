<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$command = $argv[1] ?? null;

if (count($argv) !== 2 || !in_array($command, ['lint', 'test'], true)) {
    fwrite(STDERR, "Usage: php server/bin/check.php <lint|test>\n");
    exit(2);
}

$run = static function (array $arguments) use ($root): int {
    $display = implode(' ', array_map('escapeshellarg', $arguments));
    fwrite(STDOUT, "==> {$display}\n");
    passthru($display, $status);
    return $status;
};

if ($command === 'lint') {
    $files = [];
    foreach (['api', 'server', 'test'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relativePath = substr($file->getPathname(), strlen($root) + 1);
            if ($relativePath === 'server/config.local.php') {
                continue;
            }
            $files[] = $relativePath;
        }
    }
    sort($files, SORT_STRING);

    $failed = false;
    foreach ($files as $file) {
        if ($run([PHP_BINARY, '-l', $file]) !== 0) {
            $failed = true;
        }
    }
    exit($failed ? 1 : 0);
}

$tests = [
    'test/account-deletion.test.php',
    'test/api-routing.test.php',
    'test/arcade-powerups.test.php',
    'test/arcade-v5.test.php',
    'test/app-store-api-client.test.php',
    'test/app-store-notification.test.php',
    'test/apple-jws-verifier.test.php',
    'test/apple-signin-lifecycle.test.php',
    'test/client-build.test.php',
    'test/game-center-autolink.test.php',
    'test/game-center-key-configurator.test.php',
    'test/game-center-publication.test.php',
    'test/identity-service.test.php',
    'test/identity-verifiers.test.php',
    'test/moderation-paid-value.test.php',
    'test/multiplayer-leaderboard.test.php',
    'test/multiplayer-proof.test.php',
    'test/multiplayer-service.test.php',
    'test/multiplayer-v2-auth.test.php',
    'test/nickname-profile.test.php',
    'test/php-backend.test.php',
    'test/session-registry.test.php',
    'test/storekit-domain.test.php',
];

foreach ($tests as $test) {
    if ($run([PHP_BINARY, $test]) !== 0) {
        exit(1);
    }
}
