<?php

declare(strict_types=1);

use SpeedyTapper\ClientBuild;
use SpeedyTapper\MultiplayerCatalog;
use SpeedyTapper\RunProof;

require dirname(__DIR__) . '/server/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(ClientBuild::MINIMUM_ID === '20260729-1', 'The minimum supported client build is stable.');

$cases = [
    '20260729-1' => true,
    '20260729-2' => true,
    '20260730-1' => true,
    '20260728-99' => false,
    '20260729-0' => false,
    '20260230-1' => false,
    '20260729-01' => false,
    '2026-07-29-1' => false,
    '' => false,
    202607291 => false,
];

foreach ($cases as $buildId => $expected) {
    $assert(
        ClientBuild::isSupported($buildId) === $expected,
        'ClientBuild policy result is correct for ' . var_export($buildId, true) . '.',
    );
    $assert(
        RunProof::isSupportedBuildId($buildId) === $expected,
        'Arcade build policy delegates for ' . var_export($buildId, true) . '.',
    );
    $assert(
        MultiplayerCatalog::supportsBuildId($buildId) === $expected,
        'Multiplayer build policy delegates for ' . var_export($buildId, true) . '.',
    );
}

fwrite(STDOUT, "client-build.test.php: {$assertions} assertions passed\n");
