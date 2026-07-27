<?php

declare(strict_types=1);

use SpeedyTapper\Config;

$root = dirname(__DIR__, 2);
require $root . '/server/autoload.php';

if (
    $argc !== 2
    || preg_match('/^--key-id=([A-Z0-9]{10})$/D', $argv[1], $matches) !== 1
) {
    fwrite(
        STDERR,
        "Usage: php server/bin/configure-game-center-publisher-key.php "
            . "--key-id=APP_STORE_CONNECT_KEY_ID\n",
    );
    exit(2);
}
$keyId = $matches[1];

foreach ([
    'SPEEDYTAPPER_CONFIG_PATH',
    'SPEEDYTAPPER_GAME_CENTER_API_KEY_ID',
    'SPEEDYTAPPER_GAME_CENTER_API_PRIVATE_KEY_PATH',
] as $environmentKey) {
    $environmentValue = getenv($environmentKey);
    if (is_string($environmentValue) && trim($environmentValue) !== '') {
        fwrite(
            STDERR,
            "A hosting environment override prevents private-file key rotation.\n",
        );
        exit(2);
    }
}

$home = getenv('HOME');
if (!is_string($home) || trim($home) === '') {
    fwrite(STDERR, "The hosting HOME directory is unavailable.\n");
    exit(2);
}
$directory = rtrim(trim($home), '/') . '/.config/speedytapper';
$configurationPath = $directory . '/config.php';
$privateKeyPath = $directory . '/AuthKey_' . $keyId . '.p8';
$resolvedDirectory = realpath($directory);
if (
    !is_dir($directory)
    || is_link($directory)
    || !is_string($resolvedDirectory)
) {
    fwrite(STDERR, "The private SpeedyTapper configuration directory is unsafe.\n");
    exit(2);
}
$directoryPermissions = fileperms($directory);
if (
    !is_int($directoryPermissions)
    || ($directoryPermissions & 0o022) !== 0
) {
    fwrite(STDERR, "The private SpeedyTapper directory must not be group/world-writable.\n");
    exit(2);
}
if (
    !is_file($configurationPath)
    || is_link($configurationPath)
    || !is_readable($configurationPath)
) {
    fwrite(STDERR, "The private SpeedyTapper configuration file is unavailable.\n");
    exit(2);
}
if (
    !is_file($privateKeyPath)
    || is_link($privateKeyPath)
    || !is_readable($privateKeyPath)
) {
    fwrite(STDERR, "The requested App Store Connect private key is unavailable.\n");
    exit(2);
}
foreach ([$configurationPath, $privateKeyPath] as $privatePath) {
    $permissions = fileperms($privatePath);
    if (is_int($permissions) && ($permissions & 0o077) !== 0) {
        fwrite(STDERR, "Private Game Center configuration must be owner-only.\n");
        exit(2);
    }
}

$pem = file_get_contents($privateKeyPath);
$privateKey = is_string($pem) ? openssl_pkey_get_private($pem) : false;
$details = $privateKey instanceof OpenSSLAsymmetricKey
    ? openssl_pkey_get_details($privateKey)
    : false;
if (
    !$privateKey instanceof OpenSSLAsymmetricKey
    || !is_array($details)
    || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
    || ($details['ec']['curve_name'] ?? null) !== 'prime256v1'
) {
    fwrite(STDERR, "The requested App Store Connect key is not a valid P-256 key.\n");
    exit(2);
}

$configuration = require $configurationPath;
if (!is_array($configuration)) {
    fwrite(STDERR, "The private SpeedyTapper configuration file is invalid.\n");
    exit(2);
}
foreach ([
    'SPEEDYTAPPER_STOREKIT_KEY_ID',
    'SPEEDYTAPPER_APPLE_SIGNIN_KEY_ID',
] as $separateKey) {
    $otherKeyId = $configuration[$separateKey] ?? null;
    if (is_string($otherKeyId) && hash_equals($otherKeyId, $keyId)) {
        fwrite(
            STDERR,
            "Game Center publication cannot reuse a payment or sign-in key.\n",
        );
        exit(2);
    }
}

$configuration['SPEEDYTAPPER_GAME_CENTER_API_KEY_ID'] = $keyId;
$configuration['SPEEDYTAPPER_GAME_CENTER_API_PRIVATE_KEY_PATH'] = $privateKeyPath;
$temporaryPath = $configurationPath . '.tmp-' . bin2hex(random_bytes(8));
$contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
    . var_export($configuration, true)
    . ";\n";
$previousUmask = umask(0o077);
$temporaryHandle = @fopen($temporaryPath, 'x+b');
umask($previousUmask);
if (!is_resource($temporaryHandle) || !chmod($temporaryPath, 0600)) {
    if (is_resource($temporaryHandle)) {
        fclose($temporaryHandle);
    }
    @unlink($temporaryPath);
    fwrite(STDERR, "The private Game Center configuration could not be staged.\n");
    exit(1);
}
$remaining = $contents;
$staged = true;
while ($remaining !== '') {
    $written = fwrite($temporaryHandle, $remaining);
    if (!is_int($written) || $written < 1) {
        $staged = false;
        break;
    }
    $remaining = substr($remaining, $written);
}
if (
    !$staged
    || !fflush($temporaryHandle)
    || (function_exists('fsync') && !fsync($temporaryHandle))
) {
    fclose($temporaryHandle);
    @unlink($temporaryPath);
    fwrite(STDERR, "The private Game Center configuration could not be staged.\n");
    exit(1);
}
fclose($temporaryHandle);

putenv('SPEEDYTAPPER_CONFIG_PATH=' . $temporaryPath);
try {
    $loaded = Config::load($root);
} catch (Throwable $error) {
    @unlink($temporaryPath);
    fwrite(STDERR, "The staged Game Center configuration did not validate.\n");
    exit(1);
} finally {
    putenv('SPEEDYTAPPER_CONFIG_PATH');
}
if (
    !$loaded->gameCenterPublicationIsConfigured()
    || !hash_equals($keyId, (string) $loaded->gameCenterApiKeyId)
    || !hash_equals($privateKeyPath, (string) $loaded->gameCenterApiPrivateKeyPath)
) {
    @unlink($temporaryPath);
    fwrite(STDERR, "The staged Game Center publisher selection is incomplete.\n");
    exit(1);
}
if (!rename($temporaryPath, $configurationPath)) {
    @unlink($temporaryPath);
    fwrite(STDERR, "The private Game Center configuration could not be committed.\n");
    exit(1);
}

fwrite(STDOUT, "Game Center publisher key configured: {$keyId}.\n");
