<?php

declare(strict_types=1);

use SpeedyTapper\GameCenterPublicationRepository;

require dirname(__DIR__) . '/server/autoload.php';

$dsn = getenv('SPEEDYTAPPER_TEST_MARIADB_DSN');
$user = getenv('SPEEDYTAPPER_TEST_MARIADB_USER') ?: 'root';
$password = getenv('SPEEDYTAPPER_TEST_MARIADB_PASSWORD') ?: 'root';
if (!is_string($dsn) || $dsn === '') {
    throw new RuntimeException('SPEEDYTAPPER_TEST_MARIADB_DSN is required.');
}
$connect = static function () use ($dsn, $user, $password): PDO {
    $lastError = null;
    for ($attempt = 1; $attempt <= 150; $attempt++) {
        try {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $error) {
            $lastError = $error;
            usleep(100_000);
        }
    }
    throw $lastError ?? new RuntimeException('MariaDB test connection failed.');
};
$database = $connect();

foreach ([
    'game_center_publication_outbox',
    'game_center_assertion_uses',
    'player_achievements',
    'multiplayer_results',
    'leaderboard_entries',
    'player_game_center_bindings',
    'players',
] as $table) {
    $database->exec("DROP TABLE IF EXISTS {$table}");
}
$database->exec(
    'CREATE TABLE players ('
    . 'id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, '
    . 'coins INT NOT NULL DEFAULT 0'
    . ') ENGINE=InnoDB'
);
$database->exec(
    'CREATE TABLE player_game_center_bindings ('
    . 'player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, '
    . 'team_player_id_hash BINARY(32) NOT NULL UNIQUE, '
    . 'game_player_id_hash BINARY(32) NULL UNIQUE, '
    . 'game_player_id_ciphertext VARBINARY(512) NULL, game_player_id_iv BINARY(12) NULL, '
    . 'game_player_id_tag BINARY(16) NULL, linked_at DATETIME(3) NOT NULL, '
    . 'last_verified_at DATETIME(3) NOT NULL, publication_enabled_at DATETIME(3) NULL, '
    . 'publication_disabled_at DATETIME(3) NULL, '
    . 'CONSTRAINT gc_binding_player_fk FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE'
    . ') ENGINE=InnoDB'
);
$database->exec(
    'CREATE TABLE leaderboard_entries ('
    . 'id VARCHAR(64) PRIMARY KEY, player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
    . 'mode VARCHAR(16) NOT NULL, score BIGINT NOT NULL, verification_status VARCHAR(16) NOT NULL, '
    . 'CONSTRAINT gc_score_player_fk FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE'
    . ') ENGINE=InnoDB'
);
$database->exec(
    'CREATE TABLE multiplayer_results ('
    . 'id VARCHAR(64) PRIMARY KEY, player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
    . 'score BIGINT UNSIGNED NOT NULL, verification_status VARCHAR(16) NOT NULL, '
    . 'CONSTRAINT gc_multiplayer_result_player_fk '
    . 'FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE'
    . ') ENGINE=InnoDB'
);
$database->exec(
    'CREATE TABLE player_achievements ('
    . 'player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
    . 'achievement_key VARCHAR(64) NOT NULL, PRIMARY KEY (player_id, achievement_key), '
    . 'CONSTRAINT gc_achievement_player_fk FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE'
    . ') ENGINE=InnoDB'
);
$database->exec(
    'CREATE TABLE game_center_assertion_uses ('
    . 'assertion_hash BINARY(32) PRIMARY KEY, consumed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
    . 'expires_at DATETIME(3) NOT NULL'
    . ') ENGINE=InnoDB'
);
$database->exec(
    'CREATE TABLE game_center_publication_outbox ('
    . 'id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, '
    . 'player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
    . 'publication_kind VARCHAR(16) NOT NULL, vendor_identifier VARCHAR(255) NOT NULL, '
    . 'pre_released TINYINT(1) NOT NULL, desired_value BIGINT NULL, delivered_value BIGINT NULL, '
    . 'desired_revision BIGINT UNSIGNED NOT NULL DEFAULT 1, state VARCHAR(16) NOT NULL DEFAULT \'pending\', '
    . 'attempt_count INT UNSIGNED NOT NULL DEFAULT 0, available_at DATETIME(3) NOT NULL, '
    . 'lock_token CHAR(36) NULL, locked_at DATETIME(3) NULL, apple_submission_id VARCHAR(255) NULL, '
    . 'last_http_status SMALLINT NULL, last_error_code VARCHAR(128) NULL, last_error TEXT NULL, '
    . 'created_at DATETIME(3) NOT NULL, updated_at DATETIME(3) NOT NULL, delivered_at DATETIME(3) NULL, '
    . 'UNIQUE KEY gc_outbox_unique (player_id, publication_kind, vendor_identifier, pre_released), '
    . 'CONSTRAINT gc_outbox_player_fk FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE'
    . ') ENGINE=InnoDB'
);

$players = [
    '11111111-1111-4111-8111-111111111111',
    '22222222-2222-4222-8222-222222222222',
    '33333333-3333-4333-8333-333333333333',
    '44444444-4444-4444-8444-444444444444',
];
$insertPlayer = $database->prepare('INSERT INTO players (id, coins) VALUES (?, ?)');
foreach ($players as $index => $playerId) {
    $insertPlayer->execute([$playerId, ($index + 1) * 100]);
}
$secret = str_repeat('mariadb-game-center-secret-', 2);
$teamHash = static fn (string $id): string => hash('sha256', "game_center\0" . $id, true);
$assign = static function (
    GameCenterPublicationRepository $repository,
    string $playerId,
    string $teamId,
    string $gameId,
    string $proof,
) use ($teamHash): array {
    return $repository->assignCurrentProfile(
        $playerId,
        $teamHash($teamId),
        $gameId,
        hash('sha256', "mariadb-proof\0" . $proof, true),
        gmdate('Y-m-d H:i:s', time() + 600),
    );
};
$repository = new GameCenterPublicationRepository($database, $secret, true);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$child = static function (callable $operation, string $name): array {
    $path = sys_get_temp_dir() . '/pimpopom-gc-' . $name . '-' . bin2hex(random_bytes(4));
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Could not fork MariaDB Game Center test.');
    }
    if ($pid === 0) {
        try {
            usleep(100_000);
            $operation();
            file_put_contents($path, 'ok');
            exit(0);
        } catch (Throwable $error) {
            file_put_contents($path, get_class($error) . ': ' . $error->getMessage());
            exit(1);
        }
    }
    return [$pid, $path];
};
$waitChildren = static function (array $children): void {
    foreach ($children as [$pid, $path]) {
        pcntl_waitpid($pid, $status);
        $diagnostic = is_file($path) ? (string) file_get_contents($path) : 'missing child result';
        @unlink($path);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('MariaDB child failed: ' . $diagnostic);
        }
    }
};

$database->exec(
    "INSERT INTO leaderboard_entries VALUES "
    . "('score-a', '{$players[0]}', 'normal', 111, 'verified'), "
    . "('score-c', '{$players[2]}', 'normal', 333, 'verified')"
);
$database->exec(
    "INSERT INTO player_achievements VALUES "
    . "('{$players[0]}', 'complete_arcade'), "
    . "('{$players[2]}', 'buy_a_pet')"
);

$first = $assign($repository, $players[0], 'T:first', 'G:first', 'service-first');
$assert(
    $first === [
        'enabled' => true,
        'linked' => true,
        'newlyBound' => true,
        'reassigned' => false,
    ],
    'MariaDB creates the authenticated current profile first binding.',
);
$database->exec(
    "UPDATE game_center_publication_outbox SET state = 'succeeded', "
    . 'delivered_value = desired_value, desired_revision = 9, attempt_count = 4, '
    . "apple_submission_id = 'kept-submission', delivered_at = CURRENT_TIMESTAMP(3) "
    . "WHERE player_id = '{$players[0]}'"
);
$same = $assign($repository, $players[0], 'T:first', 'G:first', 'service-same');
$assert(
    !$same['reassigned']
        && !$same['newlyBound']
        && (int) $database->query(
            "SELECT COUNT(*) FROM game_center_publication_outbox "
            . "WHERE player_id = '{$players[0]}' AND state = 'succeeded' "
            . "AND desired_revision = 9 AND apple_submission_id = 'kept-submission'"
        )->fetchColumn() === 2,
    'MariaDB exact-pair auto-link preserves succeeded delivery state.',
);

$assign($repository, $players[1], 'T:second', 'G:second', 'service-second');
$assign($repository, $players[2], 'T:third', 'G:third', 'service-current-old');
$priorCiphertext = $database->query(
    "SELECT game_player_id_ciphertext FROM player_game_center_bindings "
    . "WHERE player_id = '{$players[1]}'"
)->fetchColumn();
$customJob = $database->prepare(
    'INSERT INTO game_center_publication_outbox '
    . '(id, player_id, publication_kind, vendor_identifier, pre_released, '
    . 'desired_value, delivered_value, desired_revision, state, attempt_count, '
    . 'available_at, lock_token, locked_at, apple_submission_id, delivered_at, '
    . 'last_http_status, last_error_code, last_error, created_at, updated_at) VALUES '
    . "(:id, :player_id, 'achievement', :vendor, 0, 100, 100, 5, 'processing', 7, "
    . "CURRENT_TIMESTAMP(3), 'stale-token', CURRENT_TIMESTAMP(3), 'stale-apple', "
    . "CURRENT_TIMESTAMP(3), 500, 'STALE', 'stale error', "
    . 'CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))'
);
foreach ([$players[0], $players[1], $players[2]] as $index => $playerId) {
    $customJob->execute([
        'id' => '90000000-0000-4000-8000-00000000000' . ($index + 1),
        'player_id' => $playerId,
        'vendor' => 'test.mariadb.autolink.' . $index,
    ]);
}

$split = $assign($repository, $players[2], 'T:first', 'G:second', 'service-split');
$assert(
    $split['reassigned'] && $split['linked'] && $split['newlyBound'],
    'MariaDB resolves split team/game owners and the current old pair in one assignment.',
);
$bindingRows = $database->query(
    'SELECT player_id FROM player_game_center_bindings ORDER BY player_id'
)->fetchAll(PDO::FETCH_COLUMN);
$assert(
    $bindingRows === [$players[2]],
    'MariaDB removes displaced and current-old bindings without creating a reverse association.',
);
$currentCiphertext = $database->query(
    "SELECT game_player_id_ciphertext FROM player_game_center_bindings "
    . "WHERE player_id = '{$players[2]}'"
)->fetchColumn();
$assert(
    is_string($priorCiphertext)
        && is_string($currentCiphertext)
        && !hash_equals($priorCiphertext, $currentCiphertext),
    'MariaDB reassignment freshly encrypts the game ID for the final player/team AAD.',
);
$cancelled = $database->query(
    "SELECT * FROM game_center_publication_outbox "
    . "WHERE vendor_identifier LIKE 'test.mariadb.autolink.%' ORDER BY player_id"
)->fetchAll();
$assert(
    count($cancelled) === 3
        && array_reduce(
            $cancelled,
            static fn (bool $valid, array $job): bool =>
                $valid
                && $job['desired_value'] === null
                && $job['delivered_value'] === null
                && $job['state'] === 'cancelled'
                && (int) $job['attempt_count'] === 0
                && $job['lock_token'] === null
                && $job['locked_at'] === null
                && $job['apple_submission_id'] === null
                && $job['delivered_at'] === null
                && $job['last_http_status'] === null
                && $job['last_error_code'] === null
                && $job['last_error'] === null,
            true,
        ),
    'MariaDB revision-cancels both lanes and clears every stale delivery field.',
);
$assert(
    (int) $database->query(
        "SELECT COUNT(*) FROM game_center_publication_outbox "
        . "WHERE player_id = '{$players[2]}' AND pre_released = 1 "
        . "AND state = 'pending' AND desired_value IS NOT NULL "
        . 'AND delivered_value IS NULL AND apple_submission_id IS NULL'
    )->fetchColumn() === 2
        && (int) $database->query(
            "SELECT COUNT(*) FROM game_center_publication_outbox "
            . "WHERE player_id IN ('{$players[0]}','{$players[1]}') "
            . "AND state <> 'cancelled'"
        )->fetchColumn() === 0,
    'MariaDB backfills only the current profile after reassignment.',
);
$lease = $repository->claimNext();
$assert(is_array($lease), 'MariaDB exposes the new destination authoritative work to the worker.');
$assign($repository, $players[3], 'T:first', 'G:second', 'service-stale-lease');
$assert(
    $repository->prepareClaimForDelivery($lease) === null
        && !$repository->markSucceeded($lease, 'must-not-ack')
        && !$repository->markFailed($lease, new RuntimeException('must-not-retry')),
    'MariaDB revision fencing prevents a stale worker lease from reaching the old destination.',
);
$assert(
    $database->query('SELECT GROUP_CONCAT(coins ORDER BY id) FROM players')->fetchColumn()
        === '100,200,300,400',
    'MariaDB current-profile-wins never moves wallet state.',
);

$database->exec('DELETE FROM game_center_assertion_uses');
$database->exec('DELETE FROM game_center_publication_outbox');
$database->exec('DELETE FROM player_game_center_bindings');
$assign($repository, $players[0], 'T:one', 'G:one', 'initial-a');
$assign($repository, $players[1], 'T:two', 'G:two', 'initial-b');
$database = null;
$repository = null;
$children = [];
$children[] = $child(
    static function () use ($connect, $secret, $assign, $players): void {
        $assign(
            new GameCenterPublicationRepository($connect(), $secret, true),
            $players[0],
            'T:two',
            'G:two',
            'opposite-a',
        );
    },
    'opposite-a',
);
$children[] = $child(
    static function () use ($connect, $secret, $assign, $players): void {
        $assign(
            new GameCenterPublicationRepository($connect(), $secret, true),
            $players[1],
            'T:one',
            'G:one',
            'opposite-b',
        );
    },
    'opposite-b',
);
$waitChildren($children);
$database = $connect();
$repository = new GameCenterPublicationRepository($database, $secret, true);
$rows = $database->query(
    'SELECT player_id, HEX(team_player_id_hash) AS team_hash, HEX(game_player_id_hash) AS game_hash '
    . 'FROM player_game_center_bindings ORDER BY player_id'
)->fetchAll();
$assert(
    count($rows) === 2
        && $rows[0]['player_id'] === $players[0]
        && $rows[0]['team_hash'] === strtoupper(bin2hex($teamHash('T:two')))
        && $rows[1]['player_id'] === $players[1]
        && $rows[1]['team_hash'] === strtoupper(bin2hex($teamHash('T:one'))),
    'Opposite concurrent reassignments complete without deadlock or uniqueness loss.',
);

$database->exec('DELETE FROM game_center_publication_outbox');
$database->exec('DELETE FROM player_game_center_bindings');
$assign($repository, $players[0], 'T:race', 'G:race', 'race-initial');
$blocker = $connect();
$lockName = 'pimpopom-gc-player-' . substr(hash('sha256', $players[0]), 0, 40);
$lock = $blocker->prepare('SELECT GET_LOCK(?, 1)');
$lock->execute([$lockName]);
$assert((int) $lock->fetchColumn() === 1, 'The race fixture owns the preliminary player lock.');
$raceChild = $child(
    static function () use ($connect, $secret, $assign, $players): void {
        $assign(
            new GameCenterPublicationRepository($connect(), $secret, true),
            $players[2],
            'T:race',
            'G:race',
            'rediscovery',
        );
    },
    'rediscovery',
);
usleep(400_000);
$database->prepare(
    'UPDATE player_game_center_bindings SET player_id = ? WHERE player_id = ?'
)->execute([$players[1], $players[0]]);
$blocker->query("SELECT RELEASE_LOCK('{$lockName}')")->fetchColumn();
$waitChildren([$raceChild]);
$database = $connect();
$assert(
    $database->query(
        "SELECT player_id = '{$players[2]}' FROM player_game_center_bindings "
        . "WHERE team_player_id_hash = UNHEX('" . bin2hex($teamHash('T:race')) . "')"
    )->fetchColumn() == 1,
    'Ownership churn after discovery triggers bounded rediscovery and current-profile-wins.',
);
$assert(
    $database->query('SELECT GROUP_CONCAT(coins ORDER BY id) FROM players')->fetchColumn()
        === '100,200,300,400',
    'Concurrent Game Center reassignment never moves wallet state.',
);

fwrite(STDOUT, "MariaDB Game Center auto-link checks passed ({$assertions} assertions).\n");
