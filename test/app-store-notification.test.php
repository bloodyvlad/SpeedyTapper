<?php

declare(strict_types=1);

use SpeedyTapper\ApiException;
use SpeedyTapper\AppStoreNotificationService;
use SpeedyTapper\AppleJwsVerifier;
use SpeedyTapper\Config;
use SpeedyTapper\StoreKitNotificationProcessor;

require dirname(__DIR__) . '/server/autoload.php';

final class RecordingStoreKitProcessor implements StoreKitNotificationProcessor
{
    /** @var list<array{signedTransaction: string, notificationType: string, notificationSignedDateMs: ?int, expectedEnvironment: ?string}> */
    public array $calls = [];

    public function processNotificationTransaction(
        string $signedTransaction,
        string $notificationType,
        ?int $notificationSignedDateMs = null,
        ?string $expectedEnvironment = null,
    ): array {
        $this->calls[] = compact(
            'signedTransaction',
            'notificationType',
            'notificationSignedDateMs',
            'expectedEnvironment',
        );
        return [
            'transactionId' => 'transaction-' . count($this->calls),
            'status' => $notificationType === 'REFUND' ? 'refunded' : 'active',
            'duplicate' => false,
        ];
    }
}

$fixture = __DIR__ . '/fixtures/apple-jws';
$read = static function (string $file) use ($fixture): string {
    $value = file_get_contents($fixture . '/' . $file);
    if (!is_string($value) || $value === '') throw new RuntimeException('Missing JWS fixture.');
    return $value;
};
$root = $read('root.pem');
$intermediate = $read('intermediate.pem');
$leaf = $read('leaf.pem');
$leafKey = $read('leaf-key.pem');
$base64Url = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$certificateDer = static function (string $pem): string {
    if (preg_match(
        '/-----BEGIN CERTIFICATE-----([A-Za-z0-9+\/=\r\n]+)-----END CERTIFICATE-----/D',
        trim($pem),
        $matches,
    ) !== 1) {
        throw new RuntimeException('Invalid fixture certificate.');
    }
    $decoded = base64_decode((string) preg_replace('/\s+/', '', $matches[1]), true);
    if (!is_string($decoded)) throw new RuntimeException('Invalid fixture certificate encoding.');
    return $decoded;
};
$derToJose = static function (string $der): string {
    $offset = 0;
    $readLength = static function () use ($der, &$offset): int {
        $first = ord($der[$offset++] ?? throw new RuntimeException('Truncated DER signature.'));
        if (($first & 0x80) === 0) return $first;
        $count = $first & 0x7f;
        if ($count < 1 || $count > 2) throw new RuntimeException('Invalid DER length.');
        $length = 0;
        while ($count-- > 0) $length = ($length << 8) | ord($der[$offset++]);
        return $length;
    };
    if (($der[$offset++] ?? '') !== "\x30") throw new RuntimeException('Invalid DER sequence.');
    if ($readLength() !== strlen($der) - $offset) throw new RuntimeException('Invalid DER size.');
    $parts = [];
    for ($index = 0; $index < 2; $index++) {
        if (($der[$offset++] ?? '') !== "\x02") throw new RuntimeException('Invalid DER integer.');
        $length = $readLength();
        $integer = ltrim(substr($der, $offset, $length), "\x00");
        $offset += $length;
        $parts[] = str_pad($integer, 32, "\x00", STR_PAD_LEFT);
    }
    if ($offset !== strlen($der)) throw new RuntimeException('Invalid DER trailing data.');
    return $parts[0] . $parts[1];
};
$makeJws = static function (array $payload) use (
    $leaf,
    $leafKey,
    $intermediate,
    $root,
    $base64Url,
    $certificateDer,
    $derToJose,
): string {
    $header = [
        'alg' => 'ES256',
        'x5c' => array_map(
            static fn (string $certificate): string => base64_encode($certificateDer($certificate)),
            [$leaf, $intermediate, $root],
        ),
    ];
    $input = $base64Url(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
        . $base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
    $signature = '';
    if (!openssl_sign($input, $signature, $leafKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign notification fixture.');
    }
    return $input . '.' . $base64Url($derToJose($signature));
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$rejects = static function (callable $operation, int $status, string $message) use ($assert): void {
    try {
        $operation();
    } catch (ApiException $error) {
        $assert($error->status === $status, $message . ' Unexpected status.');
        return;
    }
    $assert(false, $message);
};

$database = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$database->exec(<<<'SQL'
CREATE TABLE storekit_notifications (
    notification_uuid TEXT PRIMARY KEY,
    apple_notification_uuid TEXT NOT NULL,
    transaction_id TEXT NULL,
    notification_type TEXT NOT NULL,
    subtype TEXT NULL,
    environment TEXT NOT NULL,
    signed_date_ms INTEGER NOT NULL,
    payload_hash BLOB NOT NULL,
    processing_status TEXT NOT NULL,
    processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
$config = new Config(
    databaseHost: 'localhost',
    databasePort: 3306,
    databaseName: 'test',
    databaseUser: 'test',
    databasePassword: 'test',
    googleClientId: 'test.apps.googleusercontent.com',
    seasonId: 'season-1',
    seasonName: 'Season 1',
    storeKitEnvironment: 'Sandbox',
    storeKitAppAppleId: '6792328590',
    storeKitEnvironments: ['Sandbox', 'Production'],
    storeKitRootCertificatePaths: [$fixture . '/root.pem'],
);
$processor = new RecordingStoreKitProcessor();
$notifications = new AppStoreNotificationService(
    $database,
    $config,
    new AppleJwsVerifier([$root]),
    $processor,
);
$nowMs = (int) floor(microtime(true) * 1_000);
$uuidIndex = 1;
$notification = static function (
    string $type,
    ?string $signedTransaction = 'signed-inner-transaction',
    array $overrides = [],
) use (&$uuidIndex, $nowMs): array {
    $data = [
        'bundleId' => 'com.otcsoftware.pimpopom',
        'environment' => 'Sandbox',
    ];
    if ($signedTransaction !== null) $data['signedTransactionInfo'] = $signedTransaction;
    return array_replace_recursive([
        'notificationType' => $type,
        'notificationUUID' => sprintf('10000000-0000-4000-8000-%012d', $uuidIndex++),
        'version' => '2.0',
        'signedDate' => $nowMs,
        'data' => $data,
    ], $overrides);
};

$activePayload = $notification('ONE_TIME_CHARGE');
$activeJws = $makeJws($activePayload);
$active = $notifications->receive($activeJws);
$assert(
    $active['status'] === 'processed'
    && $active['duplicate'] === false
    && $active['transactionId'] === 'transaction-1'
    && $processor->calls[0]['notificationType'] === 'ONE_TIME_CHARGE'
    && $processor->calls[0]['notificationSignedDateMs'] === $nowMs
    && $processor->calls[0]['expectedEnvironment'] === 'Sandbox',
    'A verified one-time charge dispatches its nested transaction with the outer lifecycle watermark.',
);
$duplicate = $notifications->receive($activeJws);
$assert(
    $duplicate['duplicate'] === true && count($processor->calls) === 1,
    'Replaying the same notification UUID and payload is idempotent before transaction dispatch.',
);
$conflicting = [...$activePayload, 'subtype' => 'CHANGED'];
$rejects(
    static fn () => $notifications->receive($makeJws($conflicting)),
    409,
    'The same notification UUID with different signed evidence is rejected.',
);

foreach (['REFUND', 'REFUND_REVERSED', 'REVOKE'] as $type) {
    $result = $notifications->receive($makeJws($notification($type)));
    $assert(
        $result['status'] === 'processed'
        && $processor->calls[array_key_last($processor->calls)]['notificationType'] === $type,
        $type . ' dispatches through the verified StoreKit lifecycle.',
    );
}
$beforeConsumption = count($processor->calls);
$consumption = $notifications->receive($makeJws($notification('CONSUMPTION_REQUEST', null)));
$assert(
    $consumption['status'] === 'ignored'
    && $consumption['transactionId'] === null
    && count($processor->calls) === $beforeConsumption,
    'Consumption requests are retained idempotently but do not disclose unreviewed consumption data.',
);
$unsupported = $notifications->receive($makeJws($notification('DID_RENEW', null)));
$assert(
    $unsupported['status'] === 'ignored' && count($processor->calls) === $beforeConsumption,
    'A valid unsupported notification type is retained without transaction mutation.',
);
$testDelivery = $notifications->receive($makeJws($notification('TEST', null)));
$assert(
    $testDelivery['status'] === 'ignored'
    && $testDelivery['transactionId'] === null
    && count($processor->calls) === $beforeConsumption,
    'Apple Notifications V2 TEST delivery is durably accepted without mutating purchases.',
);

$rejects(
    static fn () => $notifications->receive($makeJws($notification(
        'REFUND',
        null,
    ))),
    400,
    'A transaction-bearing event without signed transaction information is rejected.',
);
$rejects(
    static fn () => $notifications->receive($makeJws($notification(
        'ONE_TIME_CHARGE',
        'signed-inner-transaction',
        ['version' => '1.0'],
    ))),
    400,
    'Only Notifications V2 payloads are accepted.',
);
$rejects(
    static fn () => $notifications->receive($makeJws($notification(
        'ONE_TIME_CHARGE',
        'signed-inner-transaction',
        ['data' => ['bundleId' => 'com.attacker.wrong']],
    ))),
    400,
    'A signed notification for another bundle is rejected.',
);
$productionPayload = $notification(
    'ONE_TIME_CHARGE',
    'signed-production-transaction',
    ['data' => ['environment' => 'Production', 'appAppleId' => 6792328590]],
);
$production = $notifications->receive($makeJws($productionPayload));
$assert(
    $production['status'] === 'processed'
    && $processor->calls[array_key_last($processor->calls)]['expectedEnvironment'] === 'Production',
    'A Production V2 notification is accepted concurrently and dispatches its signed environment.',
);
$rejects(
    static fn () => $notifications->receive($makeJws($notification(
        'ONE_TIME_CHARGE',
        'signed-production-transaction',
        ['data' => ['environment' => 'Production']],
    ))),
    400,
    'A Production notification without the signed Apple App ID is rejected.',
);
$rejects(
    static fn () => $notifications->receive($makeJws($notification(
        'ONE_TIME_CHARGE',
        'signed-production-transaction',
        ['data' => ['environment' => 'Production', 'appAppleId' => 6792328591]],
    ))),
    400,
    'A Production notification with the wrong signed Apple App ID is rejected.',
);
$rejects(
    static fn () => $notifications->receive($makeJws($notification(
        'ONE_TIME_CHARGE',
        'signed-sandbox-transaction',
        ['data' => ['appAppleId' => 6792328591]],
    ))),
    400,
    'A Sandbox notification rejects a wrong Apple App ID when Apple includes one.',
);

$sharedUuid = '20000000-0000-4000-8000-000000000001';
$sandboxShared = $notification('DID_RENEW', null, [
    'notificationUUID' => $sharedUuid,
]);
$productionShared = $notification('DID_RENEW', null, [
    'notificationUUID' => $sharedUuid,
    'data' => ['environment' => 'Production', 'appAppleId' => 6792328590],
]);
$assert(
    $notifications->receive($makeJws($sandboxShared))['duplicate'] === false
    && $notifications->receive($makeJws($productionShared))['duplicate'] === false,
    'The same Apple notification UUID is independently idempotent in Sandbox and Production.',
);

fwrite(STDOUT, "App Store notification tests passed ({$assertions} assertions).\n");
