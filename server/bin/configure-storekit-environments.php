<?php

declare(strict_types=1);

use SpeedyTapper\Config;

$root = dirname(__DIR__, 2);
require $root . '/server/autoload.php';

if ($argc !== 2 || $argv[1] !== '--enable-sandbox-and-production') {
    fwrite(
        STDERR,
        "Usage: php server/bin/configure-storekit-environments.php --enable-sandbox-and-production\n",
    );
    exit(2);
}

$home = getenv('HOME');
if (!is_string($home) || trim($home) === '') {
    fwrite(STDERR, "The hosting HOME directory is unavailable.\n");
    exit(2);
}
$directory = rtrim($home, '/') . '/.config/speedytapper';
$path = $directory . '/config.php';
if (!is_file($path)) {
    fwrite(STDERR, "The private SpeedyTapper configuration file does not exist.\n");
    exit(2);
}
$configuration = require $path;
if (!is_array($configuration)) {
    fwrite(STDERR, "The private SpeedyTapper configuration file is invalid.\n");
    exit(2);
}

$configuration['SPEEDYTAPPER_STOREKIT_ENVIRONMENTS'] = ['Sandbox', 'Production'];
$configuration['SPEEDYTAPPER_STOREKIT_APP_APPLE_ID'] = '6792328590';
unset($configuration['SPEEDYTAPPER_STOREKIT_ENVIRONMENT']);

$temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
$contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
    . var_export($configuration, true)
    . ";\n";
if (file_put_contents($temporary, $contents, LOCK_EX) === false
    || !chmod($temporary, 0600)
    || !rename($temporary, $path)
) {
    @unlink($temporary);
    fwrite(STDERR, "The private SpeedyTapper configuration could not be updated.\n");
    exit(1);
}

$loaded = Config::load($root);
if ($loaded->acceptedStoreKitEnvironments() !== ['Sandbox', 'Production']
    || !$loaded->storeKitServerApiIsConfigured()
) {
    fwrite(STDERR, "The updated StoreKit configuration did not validate.\n");
    exit(1);
}

fwrite(STDOUT, "StoreKit environments enabled: Sandbox, Production.\n");
