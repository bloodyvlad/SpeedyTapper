<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$script = $projectRoot . '/server/bin/configure-game-center-publisher-key.php';
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $removeTree($path . '/' . $entry);
    }
    @rmdir($path);
};

$targetKeyId = 'D6BM4U3868';
$oldKeyId = 'F4Q3T2J79V';
$storeKitKeyId = '33469YV76A';
$signInKeyId = 'L663799XHW';

$baseConfiguration = static function (string $home) use (
    $oldKeyId,
    $storeKitKeyId,
    $signInKeyId,
): array {
    $privateDirectory = $home . '/.config/speedytapper';
    return [
        'SPEEDYTAPPER_DB_HOST' => 'localhost',
        'SPEEDYTAPPER_DB_PORT' => '3306',
        'SPEEDYTAPPER_DB_NAME' => 'speedytapper',
        'SPEEDYTAPPER_DB_USER' => 'speedytapper',
        'SPEEDYTAPPER_DB_PASSWORD' => 'fixture-password',
        'SPEEDYTAPPER_GOOGLE_CLIENT_ID' => 'fixture.apps.googleusercontent.com',
        'SPEEDYTAPPER_SEASON_ID' => 'fixture-season',
        'SPEEDYTAPPER_SEASON_NAME' => 'Fixture season',
        'SPEEDYTAPPER_STOREKIT_KEY_ID' => $storeKitKeyId,
        'SPEEDYTAPPER_STOREKIT_PRIVATE_KEY_PATH' => $privateDirectory
            . '/SubscriptionKey_' . $storeKitKeyId . '.p8',
        'SPEEDYTAPPER_APPLE_SIGNIN_KEY_ID' => $signInKeyId,
        'SPEEDYTAPPER_APPLE_SIGNIN_PRIVATE_KEY_PATH' => $privateDirectory
            . '/AuthKey_' . $signInKeyId . '.p8',
        'SPEEDYTAPPER_GAME_CENTER_API_ISSUER_ID'
            => 'ff87d914-b48b-4a89-abde-6dd04fe83684',
        'SPEEDYTAPPER_GAME_CENTER_API_KEY_ID' => $oldKeyId,
        'SPEEDYTAPPER_GAME_CENTER_API_PRIVATE_KEY_PATH' => $privateDirectory
            . '/AuthKey_' . $oldKeyId . '.p8',
        'SPEEDYTAPPER_GAME_CENTER_PLAYER_ID_ENCRYPTION_KEY'
            => str_repeat('fixture-game-center-secret-', 2),
        'SPEEDYTAPPER_GAME_CENTER_PRE_RELEASED' => true,
    ];
};

$makePrivateKey = static function (string $path): void {
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if (!$key instanceof OpenSSLAsymmetricKey) {
        throw new RuntimeException('Could not generate the fixture P-256 key.');
    }
    $pem = '';
    if (!openssl_pkey_export($key, $pem)) {
        throw new RuntimeException('Could not export the fixture P-256 key.');
    }
    file_put_contents($path, $pem);
    chmod($path, 0600);
};

$makeFixture = static function (
    array $overrides = [],
    bool $validTargetKey = true,
) use ($baseConfiguration, $makePrivateKey, $targetKeyId): array {
    $root = sys_get_temp_dir() . '/speedytapper-gc-key-' . bin2hex(random_bytes(8));
    $home = $root . '/home';
    $directory = $home . '/.config/speedytapper';
    mkdir($directory, 0700, true);
    chmod($home, 0700);
    chmod($home . '/.config', 0700);
    chmod($directory, 0700);

    $configuration = array_replace($baseConfiguration($home), $overrides);
    $configurationPath = $directory . '/config.php';
    $configurationBytes = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
        . var_export($configuration, true)
        . ";\n";
    file_put_contents($configurationPath, $configurationBytes);
    chmod($configurationPath, 0600);

    $targetPath = $directory . '/AuthKey_' . $targetKeyId . '.p8';
    if ($validTargetKey) {
        $makePrivateKey($targetPath);
    } else {
        file_put_contents($targetPath, "not a private key\n");
        chmod($targetPath, 0600);
    }

    return [
        'root' => $root,
        'home' => $home,
        'directory' => $directory,
        'configuration' => $configuration,
        'configurationPath' => $configurationPath,
        'configurationBytes' => $configurationBytes,
        'targetPath' => $targetPath,
    ];
};

$run = static function (array $fixture, array $extraEnvironment = []) use (
    $projectRoot,
    $script,
    $targetKeyId,
): array {
    $environment = getenv();
    foreach ([
        'SPEEDYTAPPER_CONFIG_PATH',
        'SPEEDYTAPPER_GAME_CENTER_API_KEY_ID',
        'SPEEDYTAPPER_GAME_CENTER_API_PRIVATE_KEY_PATH',
    ] as $name) {
        unset($environment[$name]);
    }
    $environment['HOME'] = $fixture['home'];
    foreach ($extraEnvironment as $name => $value) {
        $environment[$name] = $value;
    }
    $process = proc_open(
        [PHP_BINARY, $script, '--key-id=' . $targetKeyId],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $projectRoot,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch the key configurator fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'status' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
};

$assertNoTemporaryFiles = static function (array $fixture) use ($assert): void {
    $temporary = glob($fixture['configurationPath'] . '.tmp-*');
    $assert($temporary === [], 'A failed or completed rotation must leave no staged config.');
};

try {
    $fixture = $makeFixture();
    $result = $run($fixture);
    $assert(
        $result['status'] === 0,
        'A valid owner-only P-256 key rotates successfully: '
            . json_encode($result, JSON_THROW_ON_ERROR),
    );
    $assert(
        trim($result['stdout']) === "Game Center publisher key configured: {$targetKeyId}.",
        'Successful rotation reports only the selected public key ID.',
    );
    $assert($result['stderr'] === '', 'Successful rotation has no warning output.');
    $updated = require $fixture['configurationPath'];
    $expected = $fixture['configuration'];
    $expected['SPEEDYTAPPER_GAME_CENTER_API_KEY_ID'] = $targetKeyId;
    $expected['SPEEDYTAPPER_GAME_CENTER_API_PRIVATE_KEY_PATH'] = $fixture['targetPath'];
    $assert($updated === $expected, 'Rotation changes only the two Game Center selectors.');
    $assert(
        $updated['SPEEDYTAPPER_STOREKIT_KEY_ID'] === $storeKitKeyId
            && $updated['SPEEDYTAPPER_APPLE_SIGNIN_KEY_ID'] === $signInKeyId,
        'StoreKit and Sign in with Apple selectors are preserved.',
    );
    $assert(
        (fileperms($fixture['configurationPath']) & 0o077) === 0,
        'The committed private configuration remains owner-only.',
    );
    $assertNoTemporaryFiles($fixture);
    $removeTree($fixture['root']);

    foreach ([
        'SPEEDYTAPPER_CONFIG_PATH' => '/tmp/another-config.php',
        'SPEEDYTAPPER_GAME_CENTER_API_KEY_ID' => $oldKeyId,
        'SPEEDYTAPPER_GAME_CENTER_API_PRIVATE_KEY_PATH' => '/tmp/another-key.p8',
    ] as $overrideName => $overrideValue) {
        $fixture = $makeFixture();
        $result = $run($fixture, [$overrideName => $overrideValue]);
        $assert($result['status'] === 2, "{$overrideName} blocks file-based rotation.");
        $assert(
            file_get_contents($fixture['configurationPath'])
                === $fixture['configurationBytes'],
            "{$overrideName} cannot modify the private configuration.",
        );
        $assertNoTemporaryFiles($fixture);
        $removeTree($fixture['root']);
    }

    $fixture = $makeFixture();
    chmod($fixture['targetPath'], 0644);
    $result = $run($fixture);
    $assert($result['status'] === 2, 'A group/world-readable key is rejected.');
    $assert(
        file_get_contents($fixture['configurationPath']) === $fixture['configurationBytes'],
        'A weak key mode leaves the private configuration byte-identical.',
    );
    $assertNoTemporaryFiles($fixture);
    $removeTree($fixture['root']);

    $fixture = $makeFixture();
    $realTarget = $fixture['targetPath'] . '.real';
    rename($fixture['targetPath'], $realTarget);
    symlink($realTarget, $fixture['targetPath']);
    $result = $run($fixture);
    $assert($result['status'] === 2, 'A symlinked private key is rejected.');
    $assert(
        file_get_contents($fixture['configurationPath']) === $fixture['configurationBytes'],
        'A symlinked key leaves the private configuration byte-identical.',
    );
    $assertNoTemporaryFiles($fixture);
    $removeTree($fixture['root']);

    $fixture = $makeFixture([], false);
    $result = $run($fixture);
    $assert($result['status'] === 2, 'A non-P-256 private key is rejected.');
    $assert(
        file_get_contents($fixture['configurationPath']) === $fixture['configurationBytes'],
        'An invalid key leaves the private configuration byte-identical.',
    );
    $assertNoTemporaryFiles($fixture);
    $removeTree($fixture['root']);

    $fixture = $makeFixture(['SPEEDYTAPPER_DB_NAME' => '']);
    $result = $run($fixture);
    $assert($result['status'] === 1, 'An invalid staged runtime config is rejected.');
    $assert(
        file_get_contents($fixture['configurationPath']) === $fixture['configurationBytes'],
        'Failed staged validation leaves the original config byte-identical.',
    );
    $assertNoTemporaryFiles($fixture);
    $removeTree($fixture['root']);

    $fixture = $makeFixture();
    chmod($fixture['directory'], 0720);
    $result = $run($fixture);
    $assert($result['status'] === 2, 'A group-writable private directory is rejected.');
    $assert(
        file_get_contents($fixture['configurationPath']) === $fixture['configurationBytes'],
        'An unsafe directory leaves the private configuration byte-identical.',
    );
    $assertNoTemporaryFiles($fixture);
    $removeTree($fixture['root']);
} finally {
    foreach (glob(sys_get_temp_dir() . '/speedytapper-gc-key-*') ?: [] as $path) {
        $removeTree($path);
    }
}

fwrite(STDOUT, "Game Center key configurator checks passed ({$assertions} assertions).\n");
