<?php

declare(strict_types=1);

namespace SpeedyTapper {
    final class JsonResponse
    {
        public static function send(int $status, array $body, array $headers = []): never
        {
            throw new \V2CapturedResponse($status, $body);
        }
    }
}

namespace {
    use SpeedyTapper\ApiException;
    use SpeedyTapper\App;
    use SpeedyTapper\Config;
    use SpeedyTapper\HttpRequest;
    use SpeedyTapper\MigrationRunner;
    use SpeedyTapper\MultiplayerV2Service;
    use SpeedyTapper\MultiplayerV2ResultService;
    use SpeedyTapper\SessionRegistry;
    use SpeedyTapper\SessionStore;

    require dirname(__DIR__) . '/server/autoload.php';

    final class V2CapturedResponse extends RuntimeException
    {
        public function __construct(public readonly int $status, public readonly array $body)
        {
            parent::__construct('Captured response');
        }
    }

    $assertions = 0;
    $assert = static function (bool $condition, string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) throw new RuntimeException($message);
    };
    $throws = static function (int $status, callable $operation, string $message) use ($assert): void {
        try {
            $operation();
        } catch (ApiException $error) {
            $assert($error->status === $status, $message . ' (received ' . $error->status . ')');
            return;
        }
        $assert(false, $message);
    };
    $dsn = getenv('SPEEDYTAPPER_TEST_MARIADB_DSN') ?: 'sqlite::memory:';
    $database = new PDO($dsn, getenv('SPEEDYTAPPER_TEST_MARIADB_USER') ?: null,
        getenv('SPEEDYTAPPER_TEST_MARIADB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    $mysql = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $database->exec("SET time_zone = '+00:00'");
        $database->exec('CREATE TABLE players (id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, '
            . 'nickname VARCHAR(20) NOT NULL, nickname_confirmed TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB');
        $database->exec('CREATE TABLE player_pet_selection (player_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, '
            . 'pet_id VARCHAR(32), is_visible TINYINT NOT NULL DEFAULT 1, '
            . 'FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE) ENGINE=InnoDB');
        foreach (['015_player_sessions.sql', '023_multiplayer_v2_auth.sql'] as $migration) {
            $sql = file_get_contents(dirname(__DIR__) . '/server/migrations/' . $migration);
            foreach (MigrationRunner::splitStatements($sql) as $statement) $database->exec($statement);
        }
        // Additive migration can safely be applied twice without erasing records.
        foreach (MigrationRunner::splitStatements($sql) as $statement) $database->exec($statement);
    } else {
        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec('CREATE TABLE players (id TEXT PRIMARY KEY, nickname TEXT NOT NULL, nickname_confirmed INTEGER NOT NULL DEFAULT 1)');
        $database->exec('CREATE TABLE player_pet_selection (player_id TEXT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE, '
            . 'pet_id TEXT, is_visible INTEGER NOT NULL DEFAULT 1)');
        $database->exec('CREATE TABLE player_sessions (session_auth_hash BLOB PRIMARY KEY, '
            . 'player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, expires_at TEXT NOT NULL)');
        foreach (['multiplayer_v2_tickets' => 'ticket_hash', 'multiplayer_v2_connections' => 'binding_hash'] as $table => $key) {
            $database->exec('CREATE TABLE ' . $table . ' (' . $key . ' BLOB PRIMARY KEY, '
                . 'player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, '
                . 'session_auth_hash BLOB NOT NULL REFERENCES player_sessions(session_auth_hash) ON DELETE CASCADE, '
                . 'protocol_version INTEGER NOT NULL, ruleset_id TEXT NOT NULL, created_at TEXT NOT NULL, expires_at TEXT NOT NULL'
                . ($key === 'ticket_hash' ? ', consumed_at TEXT NULL' : '') . ')');
        }
        $database->exec('CREATE TABLE multiplayer_v2_results (match_id TEXT PRIMARY KEY, payload_hash BLOB NOT NULL, '
            . 'protocol_version INTEGER NOT NULL, ruleset_id TEXT NOT NULL, duration_ms INTEGER NOT NULL, created_at TEXT NOT NULL)');
        $database->exec('CREATE TABLE multiplayer_v2_result_players (match_id TEXT NOT NULL REFERENCES multiplayer_v2_results(match_id) ON DELETE CASCADE, '
            . 'player_id TEXT NOT NULL REFERENCES players(id) ON DELETE CASCADE, seat INTEGER NOT NULL, score INTEGER NOT NULL, lives INTEGER NOT NULL, '
            . 'hits INTEGER NOT NULL, misses INTEGER NOT NULL, dodges INTEGER NOT NULL, reaction_total_ms INTEGER NOT NULL, fastest_reaction_ms INTEGER NULL, '
            . 'PRIMARY KEY(match_id, player_id), UNIQUE(match_id, seat))');
    }

    $player = '11111111-1111-4111-8111-111111111111';
    $other = '22222222-2222-4222-8222-222222222222';
    $database->prepare('INSERT INTO players (id, nickname) VALUES (?, ?)')->execute([$player, 'PlayerOne']);
    $database->prepare('INSERT INTO players (id, nickname) VALUES (?, ?)')->execute([$other, 'PlayerTwo']);
    $database->prepare('INSERT INTO player_pet_selection (player_id, pet_id) VALUES (?, ?)')->execute([$player, 'foka']);
    $now = time();
    $config = new Config('', 0, '', '', '', '', '', '', realtimeUrl: 'ws://127.0.0.1:8080/socket',
        multiplayerServiceSecret: str_repeat('s', 64));
    $service = new MultiplayerV2Service($database, $config, static function () use (&$now): int { return $now; });
    $contract = ['protocolVersion' => 2, 'ruleset' => MultiplayerV2Service::RULESET];
    $token = static fn (): string => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $auth = $token();
    $registry = new SessionRegistry($database);
    $registry->rotate(null, $auth, $player);
    $hash = hash('sha256', $auth, true);

    $disabled = new MultiplayerV2Service($database, new Config('', 0, '', '', '', '', '', ''));
    $throws(503, fn () => $disabled->issue($player, $hash, $contract), 'V2 is disabled by default');
    $assert(!$database->query('SELECT COUNT(*) FROM multiplayer_v2_tickets')->fetchColumn(), 'Disabled service writes nothing');
    foreach (['ws://example.com/socket', 'wss://user:pass@example.com/socket', 'wss://example.com/socket?token=x', 'https://example.com'] as $url) {
        $bad = new Config('', 0, '', '', '', '', '', '', realtimeUrl: $url, multiplayerServiceSecret: str_repeat('s', 64));
        $assert(!$bad->multiplayerV2IsConfigured(), 'Unsafe realtime URL fails closed: ' . $url);
    }
    $throws(409, fn () => $service->issue($player, $hash, ['protocolVersion' => 1, 'ruleset' => 'multiplayer-own-color-v1']), 'V1 cannot enter V2');
    $throws(409, fn () => $service->issue($player, $hash, ['protocolVersion' => '2', 'ruleset' => MultiplayerV2Service::RULESET]), 'Protocol type is exact');
    $throws(400, fn () => $service->issue($player, $hash, $contract + ['playerID' => $other]), 'Identity claims rejected');
    $throws(401, fn () => $service->issue($other, $hash, $contract), 'Session cannot bind another player');
    $database->prepare('UPDATE players SET nickname_confirmed = 0 WHERE id = ?')->execute([$player]);
    $throws(403, fn () => $service->issue($player, $hash, $contract), 'Confirmed name is mandatory');
    $database->prepare('UPDATE players SET nickname_confirmed = 1 WHERE id = ?')->execute([$player]);
    $issued = $service->issue($player, $hash, $contract);
    $assert($issued['expiresAt'] === $now + 60 && strlen($issued['ticket']) === 43, 'Ticket has 256-bit entropy and 60s lifetime');
    $stored = $database->query('SELECT * FROM multiplayer_v2_tickets')->fetch();
    $assert(strlen($stored['ticket_hash']) === 32 && !str_contains(serialize($stored), $issued['ticket']), 'Ticket stored only as digest');
    $redeemed = $service->redeem($contract + ['ticket' => $issued['ticket']]);
    $assert($redeemed['playerID'] === $player && $redeemed['name'] === 'PlayerOne' && $redeemed['petID'] === 'foka', 'Authoritative name/pet returned without Game Center');
    $assert($redeemed['expiresAt'] === $now + 3600 && strlen($redeemed['sessionBinding']) === 43, 'Independent one-hour connection binding');
    $assert(!str_contains(serialize($database->query('SELECT * FROM multiplayer_v2_connections')->fetch()), $redeemed['sessionBinding']), 'Connection secret stored only as digest');
    $throws(401, fn () => $service->redeem($contract + ['ticket' => $issued['ticket']]), 'Ticket single use');
    $validation = $contract + ['playerID' => $player, 'sessionBinding' => $redeemed['sessionBinding']];
    $assert($service->validateSession($validation) === $redeemed, 'Validation matches identity without extending expiry');
    if ($mysql) {
        $raceTicket = $service->issue($player, $hash, $contract);
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/multiplayer-v2-redeem-worker.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start concurrent redemption worker.');
            $workers[] = [$process, $pipes];
        }
        foreach ($workers as [$process, $pipes]) {
            fwrite($pipes[0], json_encode($contract + ['ticket' => $raceTicket['ticket']], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
        }
        $statuses = [];
        foreach ($workers as [$process, $pipes]) {
            $statuses[] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $assert(proc_close($process) === 0 && $errors === '', 'Concurrent worker completes without database error');
        }
        sort($statuses);
        $assert($statuses === ['200', '401'], 'Concurrent ticket redemption has exactly one winner');
    }
    $throws(401, fn () => $service->validateSession(array_replace($validation, ['playerID' => $other])), 'Binding cannot move accounts');
    $database->prepare('UPDATE player_pet_selection SET is_visible = 0 WHERE player_id = ?')->execute([$player]);
    $assert($service->validateSession($validation)['petID'] === null, 'Private pet omitted on revalidation');
    $expiredTicket = $service->issue($player, $hash, $contract);
    $now += 60;
    $throws(401, fn () => $service->redeem($contract + ['ticket' => $expiredTicket['ticket']]), 'Ticket exactly at expiry is invalid');
    $now += 3540;
    $throws(401, fn () => $service->validateSession($validation), 'Binding exactly at expiry is invalid');
    $now = time();

    $pending = $service->issue($player, $hash, $contract);
    $registry->revoke($auth);
    $throws(401, fn () => $service->redeem($contract + ['ticket' => $pending['ticket']]), 'Logout revokes unused tickets');
    $throws(401, fn () => $service->validateSession($validation), 'Logout revokes open connections');
    $assert((int) $database->query('SELECT COUNT(*) FROM multiplayer_v2_connections')->fetchColumn() === 0, 'Logout cascade erases connection records');
    $auth = $token();
    $registry->rotate(null, $auth, $player);
    $hash = hash('sha256', $auth, true);
    for ($i = 0; $i < 12; $i++) $service->issue($player, $hash, $contract);
    $throws(429, fn () => $service->issue($player, $hash, $contract), 'Session ticket issuance is rate limited');
    $replacement = $token();
    $registry->rotate($auth, $replacement, $player);
    $assert((int) $database->query('SELECT COUNT(*) FROM multiplayer_v2_tickets')->fetchColumn() === 0, 'Rotation removes prior session tickets');
    $auth = $replacement;
    $hash = hash('sha256', $auth, true);

    // Repeated socket reconnects must not poison an otherwise valid primary login.
    // Another device/session for the same player retains independent credentials.
    $reconnectAuth = $token();
    $independentAuth = $token();
    $registry->rotate(null, $reconnectAuth, $player);
    $registry->rotate(null, $independentAuth, $player);
    $reconnectHash = hash('sha256', $reconnectAuth, true);
    $independentTicket = $service->issue($player, hash('sha256', $independentAuth, true), $contract);
    $independentIdentity = $service->redeem($contract + ['ticket' => $independentTicket['ticket']]);
    $bindingRows = static function () use ($database, $reconnectHash): array {
        $select = $database->prepare(
            'SELECT binding_hash FROM multiplayer_v2_connections WHERE session_auth_hash = :hash ORDER BY binding_hash'
        );
        $select->bindValue(':hash', $reconnectHash, PDO::PARAM_LOB);
        $select->execute();
        return $select->fetchAll(PDO::FETCH_COLUMN);
    };
    $reconnectBindings = [];
    for ($i = 0; $i < 20; $i++) {
        $now += 6; // Stay under the unchanged 12 tickets/minute issuance rate.
        $reconnectTicket = $service->issue($player, $reconnectHash, $contract);
        $identity = $service->redeem($contract + ['ticket' => $reconnectTicket['ticket']]);
        $reconnectBindings[] = $contract + ['playerID' => $player, 'sessionBinding' => $identity['sessionBinding']];
        $assert(count($bindingRows()) === min(12, $i + 1), 'Reconnect credentials stay bounded after redemption ' . ($i + 1));
    }
    $assert($registry->resolve($reconnectAuth) === $player, 'Twenty reconnects retain the original primary login');
    foreach (array_slice($reconnectBindings, 0, 8) as $evicted) {
        $throws(401, fn () => $service->validateSession($evicted), 'Oldest superseded binding is revoked');
    }
    foreach (array_slice($reconnectBindings, 8) as $retained) {
        $assert($service->validateSession($retained)['playerID'] === $player, 'Recent bounded binding remains valid');
    }
    $independentValidation = $contract + ['playerID' => $player, 'sessionBinding' => $independentIdentity['sessionBinding']];
    $assert($service->validateSession($independentValidation) === $independentIdentity, 'Same-player other session is unaffected');
    $beforeInvalid = $bindingRows();
    $throws(401, fn () => $service->redeem($contract + ['ticket' => $token()]), 'Unknown ticket cannot evict a binding');
    $throws(401, fn () => $service->redeem($contract + ['ticket' => $reconnectTicket['ticket']]), 'Replayed ticket cannot evict a binding');
    $assert($bindingRows() === $beforeInvalid, 'Invalid and replayed tickets preserve every current binding');
    $now += 6;
    $expiredReconnect = $service->issue($player, $reconnectHash, $contract);
    $now += 60;
    $throws(401, fn () => $service->redeem($contract + ['ticket' => $expiredReconnect['ticket']]), 'Expired ticket cannot evict a binding');
    $assert($bindingRows() === $beforeInvalid, 'Expired ticket preserves every current binding');
    $registry->revoke($reconnectAuth);
    $throws(401, fn () => $service->validateSession($reconnectBindings[19]), 'Logout still revokes newest reconnect binding');
    $assert($bindingRows() === [], 'Logout deletes all retained reconnect bindings');
    $assert($service->validateSession($independentValidation) === $independentIdentity, 'Revocation remains scoped to one session');
    $registry->revoke($independentAuth);
    $now = time();

    $resultService = new MultiplayerV2ResultService($database);
    $resultBody = $contract + [
        'matchID' => '33333333-3333-4333-8333-333333333333', 'durationMs' => 12345, 'rankingEligible' => false,
        'players' => [
            ['playerID' => $player, 'seat' => 0, 'score' => 12500, 'lives' => 0, 'hits' => 12, 'misses' => 3,
                'dodges' => 2, 'reactionTotalMs' => 2500, 'fastestReactionMs' => 190],
            ['playerID' => $other, 'seat' => 1, 'score' => 9500, 'lives' => 0, 'hits' => 10, 'misses' => 3,
                'dodges' => 1, 'reactionTotalMs' => 2800, 'fastestReactionMs' => 220],
        ],
    ];
    $result = $resultService->store($resultBody);
    $assert($result === ['matchID' => $resultBody['matchID'], 'duplicate' => false, 'rankingEligible' => false, 'state' => 'stored_unranked'], 'Alpha results stored explicitly unranked');
    $assert($resultService->store($resultBody)['duplicate'], 'Result retry is idempotent');
    $reordered = $resultBody;
    $reordered['players'] = array_reverse($reordered['players']);
    $assert($resultService->store($reordered)['duplicate'], 'Seat ordering does not create a false payload conflict');
    $changed = $resultBody;
    $changed['players'][0]['score']++;
    $throws(409, fn () => $resultService->store($changed), 'Immutable result conflict rejected');
    $throws(400, fn () => $resultService->store(array_replace($resultBody, ['rankingEligible' => true])), 'Alpha cannot opt into ranking');
    $throws(400, fn () => $resultService->store($resultBody + ['coins' => 10]), 'Economy claims rejected');
    $throws(400, fn () => $resultService->store(array_replace($resultBody, ['durationMs' => 900001])), 'Duration bound retained');
    $changed = $resultBody;
    $changed['players'][1]['playerID'] = $player;
    $throws(400, fn () => $resultService->store($changed), 'Duplicate player rejected');
    $changed = $resultBody;
    $changed['players'][1]['seat'] = 0;
    $throws(400, fn () => $resultService->store($changed), 'Duplicate seat rejected');
    $changed = $resultBody;
    $changed['players'][1]['score'] = 10.5;
    $throws(400, fn () => $resultService->store($changed), 'Float metric rejected');
    $changed = $resultBody;
    $changed['players'][1]['playerID'] = '44444444-4444-4444-8444-444444444444';
    $throws(409, fn () => $resultService->store($changed), 'Unknown identity cannot be created by result delivery');
    $assert((int) $database->query('SELECT COUNT(*) FROM multiplayer_v2_results')->fetchColumn() === 1, 'Invalid retries create no extra result records');
    $storedResult = serialize($database->query('SELECT * FROM multiplayer_v2_results')->fetch());
    $assert(!str_contains($storedResult, $player) && !str_contains($storedResult, 'PlayerOne'), 'Match receipt stores no roster or public names');
    $ticket = $service->issue($player, $hash, $contract);
    $identity = $service->redeem($contract + ['ticket' => $ticket['ticket']]);
    $database->beginTransaction();
    $resultService->purgePlayerInCurrentTransaction($player);
    $database->prepare('DELETE FROM players WHERE id = ?')->execute([$player]);
    $database->commit();
    $assert((int) $database->query('SELECT COUNT(*) FROM multiplayer_v2_results')->fetchColumn() === 0, 'Deletion purges shared alpha receipt');
    $assert((int) $database->query('SELECT COUNT(*) FROM multiplayer_v2_result_players')->fetchColumn() === 0, 'Deletion purges all shared alpha seat data');
    $throws(409, fn () => $resultService->store($resultBody), 'Late outbox cannot recreate a deleted account result');
    $throws(401, fn () => $service->validateSession($contract + ['playerID' => $player, 'sessionBinding' => $identity['sessionBinding']]), 'Account deletion revokes bindings');
    $assert((int) $database->query('SELECT COUNT(*) FROM multiplayer_v2_tickets')->fetchColumn() === 0, 'Deletion purges unused and consumed tickets');
    $assert((int) $database->query('SELECT COUNT(*) FROM multiplayer_v2_connections')->fetchColumn() === 0, 'Deletion purges connection credentials');

    // Exercise actual App dispatch before any output so PHP session headers remain valid.
    $sessionDirectory = sys_get_temp_dir() . '/speedytapper-v2-session-' . bin2hex(random_bytes(6));
    mkdir($sessionDirectory, 0700);
    session_save_path($sessionDirectory);
    session_id('speedytapperv2' . bin2hex(random_bytes(8)));
    $session = new SessionStore(false, $registry);
    $reflection = new ReflectionClass(App::class);
    $app = $reflection->newInstanceWithoutConstructor();
    foreach (['multiplayerV2' => $service, 'multiplayerV2Results' => $resultService, 'session' => $session] as $property => $value) {
        $reflection->getProperty($property)->setValue($app, $value);
    }
    $dispatch = static function (HttpRequest $request) use ($app): V2CapturedResponse {
        try { $app->dispatch($request); } catch (V2CapturedResponse $response) { return $response; }
    };
    $ticketPath = '/api/mobile/v2/multiplayer/tickets';
    $body = json_encode($contract, JSON_THROW_ON_ERROR);
    $throws(403, fn () => $dispatch(new HttpRequest('POST', $ticketPath, [], [], $body)), 'Public ticket route requires CSRF');
    $csrf = $session->csrfToken();
    $throws(401, fn () => $dispatch(new HttpRequest('POST', $ticketPath, [], ['HTTP_X_SPEEDYTAPPER_CSRF' => $csrf], $body)), 'CSRF alone cannot authenticate');
    $session->login($other, 'apple');
    $_COOKIE[session_name()] = session_id();
    $headers = ['HTTP_X_SPEEDYTAPPER_CSRF' => $session->csrfToken()];
    $throws(403, fn () => $dispatch(new HttpRequest('POST', $ticketPath, [], $headers + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], $body)), 'Cross-site cookie mutations rejected');
    $throws(413, fn () => $dispatch(new HttpRequest('POST', $ticketPath, [], $headers, str_repeat(' ', 1025))), 'Ticket request byte bound');
    $response = $dispatch(new HttpRequest('POST', $ticketPath, [], $headers, $body));
    $assert($response->status === 201, 'Authenticated native request without Origin issues ticket');
    $cookieId = $_COOKIE[session_name()];
    unset($_COOKIE[session_name()]);
    $redeemPath = '/api/internal/multiplayer/v2/tickets/redeem';
    $redeemBody = json_encode($contract + ['ticket' => $response->body['ticket']], JSON_THROW_ON_ERROR);
    $throws(401, fn () => $dispatch(new HttpRequest('POST', $redeemPath, [], $headers, $redeemBody)), 'Cookie and CSRF cannot authorize internal service');
    $serviceHeaders = ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('s', 64)];
    $throws(401, fn () => $dispatch(new HttpRequest('POST', $redeemPath, [], ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('x', 64)], $redeemBody)), 'Wrong service secret rejected');
    $throws(413, fn () => $dispatch(new HttpRequest('POST', $redeemPath, [], $serviceHeaders, str_repeat(' ', 1025))), 'Service request byte bound');
    $response = $dispatch(new HttpRequest('POST', $redeemPath, [], $serviceHeaders, $redeemBody));
    $assert($response->status === 200 && $response->body['playerID'] === $other, 'Internal redemption requires no cookie or CSRF');
    $validateBody = json_encode($contract + ['playerID' => $other, 'sessionBinding' => $response->body['sessionBinding']], JSON_THROW_ON_ERROR);
    $response = $dispatch(new HttpRequest('POST', '/api/internal/multiplayer/v2/sessions/validate', [], $serviceHeaders, $validateBody));
    $assert($response->status === 200, 'Service validation endpoint dispatched');
    $resultPath = '/api/internal/multiplayer/v2/results';
    $throws(401, fn () => $dispatch(new HttpRequest('POST', $resultPath, [], $headers, json_encode($resultBody))), 'Cookie cannot author server results');
    $throws(413, fn () => $dispatch(new HttpRequest('POST', $resultPath, [], $serviceHeaders, str_repeat(' ', 16385))), 'Result body bound enforced');
    $third = '55555555-5555-4555-8555-555555555555';
    $database->prepare('INSERT INTO players (id, nickname) VALUES (?, ?)')->execute([$third, 'PlayerThree']);
    $resultBody['players'][0]['playerID'] = $third;
    $response = $dispatch(new HttpRequest('POST', $resultPath, [], $serviceHeaders, json_encode($resultBody)));
    $assert($response->status === 200 && !$response->body['rankingEligible'], 'Authenticated result route stores unranked aggregate');
    $_COOKIE[session_name()] = $cookieId;
    $session->logout();
    $throws(401, fn () => $dispatch(new HttpRequest('POST', '/api/internal/multiplayer/v2/sessions/validate', [], $serviceHeaders, $validateBody)), 'Actual cookie logout revokes socket binding');
    @rmdir($sessionDirectory);
    fwrite(STDOUT, 'Multiplayer v2 auth checks passed (' . $assertions . ' assertions; ' . ($mysql ? 'MariaDB' : 'SQLite') . ").\n");
}
