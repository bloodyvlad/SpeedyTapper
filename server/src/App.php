<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class App
{
    public function __construct(
        private readonly Config $config,
        private readonly PlayerRepository $players,
        private readonly PetShopService $pets,
        private readonly LeaderboardRepository $leaderboard,
        private readonly RunAttemptService $attempts,
        private readonly AchievementService $achievements,
        private readonly RunSubmissionService $runs,
        private readonly LeaderboardModerationService $moderation,
        private readonly SessionStore $session,
        private readonly GoogleIdentityVerifier $google,
    ) {
    }

    public function dispatch(HttpRequest $request): never
    {
        if ($request->method === 'GET' && $request->path === '/api/health') {
            JsonResponse::send(200, [
                'ok' => true,
                'season' => ['id' => $this->config->seasonId, 'name' => $this->config->seasonName],
            ]);
        }

        if ($request->method === 'GET' && $request->path === '/api/session') {
            JsonResponse::send(200, $this->sessionPayload());
        }

        if ($request->method === 'POST' && $request->path === '/api/auth/google') {
            $this->guardMutation($request);
            $body = $request->json();
            $credential = $body['credential'] ?? null;
            if (!is_string($credential)) {
                throw new ApiException(400, 'Google credential is required.');
            }
            $profile = $this->players->findOrCreate($this->google->verify($credential));
            $this->session->login($profile['id']);
            JsonResponse::send(200, $this->sessionPayload());
        }

        if ($request->method === 'POST' && $request->path === '/api/logout') {
            $this->guardMutation($request);
            $this->session->logout();
            JsonResponse::send(200, $this->sessionPayload());
        }

        if ($request->path === '/api/profile' && ($request->method === 'GET' || $request->method === 'PATCH')) {
            $profile = $this->requirePlayer();
            if ($request->method === 'PATCH') {
                $this->guardMutation($request);
                $body = $request->json();
                $profile = $this->players->updateNickname($profile['id'], $body['nickname'] ?? null);
            }
            $mode = $this->modeFromQuery($request);
            JsonResponse::send(200, [
                'profile' => $profile,
                'ranks' => $this->leaderboard->rankings($profile['id']),
                'leaderboard' => $this->leaderboard->payload($mode, $profile['id']),
            ]);
        }

        if ($request->method === 'GET' && $request->path === '/api/pets') {
            $playerId = $this->session->playerId();
            $profile = $playerId === null ? null : $this->players->find($playerId);
            if ($playerId !== null && $profile === null) {
                $this->session->logout();
            }
            JsonResponse::send(200, [
                'pets' => PetCatalog::all(),
                'profile' => $profile,
                'coinBalance' => $profile['coins'] ?? 0,
            ]);
        }

        if ($request->method === 'POST' && $request->path === '/api/pets/select') {
            $this->guardMutation($request);
            $profile = $this->requirePlayer();
            $result = $this->pets->select($profile['id'], $request->json()['petId'] ?? null);
            $profile = $this->players->find($profile['id'])
                ?? throw new ApiException(401, 'Sign in with Google to continue.');
            JsonResponse::send($result['purchased'] ? 201 : 200, [
                'profile' => $profile,
                'pet' => [
                    'id' => $result['pet']['id'],
                    'purchased' => $result['purchased'],
                    'pricePaid' => $result['pricePaid'],
                ],
                'coinBalance' => $profile['coins'],
            ]);
        }

        if ($request->method === 'PATCH' && $request->path === '/api/pets/selection') {
            $this->guardMutation($request);
            $profile = $this->requirePlayer();
            $body = $request->json();
            $result = $this->pets->setVisibility(
                $profile['id'],
                $body['petId'] ?? null,
                $body['visible'] ?? null,
            );
            $profile = $this->players->find($profile['id'])
                ?? throw new ApiException(401, 'Sign in with Google to continue.');
            JsonResponse::send(200, [
                'profile' => $profile,
                'pet' => [
                    'id' => $result['pet']['id'],
                    'visible' => $result['visible'],
                ],
                'coinBalance' => $profile['coins'],
            ]);
        }

        if ($request->path === '/api/leaderboard' && $request->method === 'GET') {
            $mode = $this->modeFromQuery($request);
            $playerId = $this->session->playerId();
            if ($playerId !== null && $this->players->find($playerId) === null) {
                $this->session->logout();
                $playerId = null;
            }
            JsonResponse::send(200, $this->leaderboard->payload($mode, $playerId));
        }

        if ($request->path === '/api/achievements' && $request->method === 'GET') {
            $playerId = $this->session->playerId();
            if ($playerId !== null && $this->players->find($playerId) === null) {
                $this->session->logout();
                $playerId = null;
            }
            JsonResponse::send(200, $this->achievements->payload($playerId));
        }

        if ($request->path === '/api/achievements/claim' && $request->method === 'POST') {
            $this->guardMutation($request);
            $profile = $this->requirePlayer();
            $result = $this->achievements->claim(
                $profile['id'],
                $request->json()['id'] ?? null,
            );
            JsonResponse::send($result['duplicate'] ? 200 : 201, $result);
        }

        if ($request->path === '/api/runs' && $request->method === 'POST') {
            $this->guardMutation($request);
            $profile = $this->requirePlayer();
            if (($profile['nicknameConfirmed'] ?? false) !== true) {
                throw new ApiException(409, 'Choose a public nickname before starting a ranked run.');
            }
            $body = $request->json();
            JsonResponse::send(201, $this->attempts->start(
                $profile['id'],
                $this->session->runBindingHash(),
                $body['mode'] ?? null,
                $body['buildId'] ?? null,
            ));
        }

        if ($request->path === '/api/runs/abandon' && $request->method === 'POST') {
            $this->guardMutation($request);
            $this->attempts->abandon(
                $this->session->runBindingHash(),
                $request->json()['runId'] ?? null,
            );
            JsonResponse::send(200, ['abandoned' => true]);
        }

        if ($request->path === '/api/runs/finish' && $request->method === 'POST') {
            $this->guardMutation($request);
            $profile = $this->requirePlayer();
            if (($profile['nicknameConfirmed'] ?? false) !== true) {
                throw new ApiException(409, 'Choose a public nickname before saving a score.');
            }
            // Count the request before parsing or normalizing its proof so malformed
            // authenticated bodies cannot repeatedly consume replay CPU for free.
            $this->session->requireRunFinishCapacity();
            $proof = RunProof::fromArray($request->json());
            $result = $this->runs->submit(
                $profile['id'],
                $this->session->runBindingHash(),
                $proof,
            );
            JsonResponse::send($result['duplicate'] ? 200 : 201, $result);
        }

        if ($request->path === '/api/leaderboard' && $request->method === 'POST') {
            throw new ApiException(410, 'Aggregate score submission is retired. Refresh before playing again.');
        }

        if ($request->path === '/api/admin/leaderboard' && $request->method === 'GET') {
            $this->requireAdmin();
            [$offset, $limit] = $this->adminPagination($request);
            $mode = $this->adminModeFromQuery($request);
            $status = $this->adminStatusFromQuery($request);
            $view = $request->query['view'] ?? 'all';
            if (!is_string($view) || ($view !== 'all' && $view !== 'scan')) {
                throw new ApiException(400, 'Admin view must be all or scan.');
            }
            if ($view === 'scan') {
                $payload = $this->adminOperation(fn (): array => $this->moderation->scan(
                    $this->config->seasonId,
                    $mode,
                    $status,
                    $limit,
                    $offset,
                ));
                JsonResponse::send(200, ['view' => 'scan', ...$payload]);
            }
            $rows = $this->adminOperation(fn (): array => $this->moderation->listEntries(
                $this->config->seasonId,
                $mode,
                $status,
                $limit + 1,
                $offset,
            ));
            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }
            JsonResponse::send(200, [
                'view' => 'all',
                'entries' => $rows,
                'offset' => $offset,
                'limit' => $limit,
                'hasMore' => $hasMore,
            ]);
        }

        if (
            $request->method === 'GET'
            && preg_match(
                '#^/api/admin/leaderboard/entries/([0-9a-fA-F-]{36})$#D',
                $request->path,
                $matches,
            ) === 1
        ) {
            $this->requireAdmin();
            $detail = $this->adminOperation(
                fn (): array => $this->moderation->showForAdmin($matches[1]),
            );
            JsonResponse::send(200, $detail);
        }

        if (
            $request->method === 'POST'
            && preg_match(
                '#^/api/admin/leaderboard/entries/([0-9a-fA-F-]{36})/(quarantine|delete-reset)$#D',
                $request->path,
                $matches,
            ) === 1
        ) {
            $this->guardMutation($request);
            $admin = $this->requireAdmin(true);
            $body = $request->json();
            if (($body['confirm'] ?? null) !== true) {
                throw new ApiException(400, 'Explicit confirmation is required.');
            }
            $reason = $body['reason'] ?? null;
            $expectedStatus = $body['expectedStatus'] ?? null;
            if (!is_string($reason) || !is_string($expectedStatus)) {
                throw new ApiException(400, 'Reason and expected status are required.');
            }
            $entryId = $matches[1];
            if ($matches[2] === 'quarantine') {
                $result = $this->adminOperation(fn (): array => $this->moderation->transition(
                    $entryId,
                    'quarantine',
                    'admin:' . $admin['id'],
                    $reason,
                    true,
                    $expectedStatus,
                    $admin['id'],
                ));
                JsonResponse::send(200, $result);
            }
            $result = $this->adminOperation(fn (): array => $this->moderation->deleteAndReset(
                $entryId,
                $admin['id'],
                $reason,
                $expectedStatus,
                $body['confirmPlayerId'] ?? null,
            ));
            JsonResponse::send(200, $result);
        }

        throw new ApiException(404, 'API route not found.');
    }

    private function sessionPayload(): array
    {
        $playerId = $this->session->playerId();
        $profile = $playerId === null ? null : $this->players->find($playerId);
        if ($playerId !== null && $profile === null) {
            $this->session->logout();
        }
        $csrfToken = $this->session->csrfToken();

        return [
            'authenticated' => $profile !== null,
            'csrfToken' => $csrfToken,
            'googleClientId' => $this->config->googleClientId,
            'season' => ['id' => $this->config->seasonId, 'name' => $this->config->seasonName],
            'profile' => $profile,
            'ranks' => $profile === null ? null : $this->leaderboard->rankings($profile['id']),
        ];
    }

    private function guardMutation(HttpRequest $request): void
    {
        $request->guardSameOriginMutation();
        $this->session->requireCsrf($request);
    }

    private function requirePlayer(): array
    {
        $playerId = $this->session->playerId();
        $profile = $playerId === null ? null : $this->players->find($playerId);
        if ($profile === null) {
            if ($playerId !== null) {
                $this->session->logout();
            }
            throw new ApiException(401, 'Sign in with Google to continue.');
        }
        return $profile;
    }

    private function requireAdmin(bool $requireRecentGoogleAuthentication = false): array
    {
        $profile = $this->requirePlayer();
        if (($profile['isAdmin'] ?? false) !== true) {
            throw new ApiException(403, 'Leaderboard administrator access is required.');
        }
        if ($requireRecentGoogleAuthentication) {
            $this->session->requireRecentGoogleAuthentication();
        }
        return $profile;
    }

    /** @return array{int, int} */
    private function adminPagination(HttpRequest $request): array
    {
        $offset = $this->boundedQueryInteger($request->query['offset'] ?? '0', 'offset', 0, 10_000_000);
        $limit = $this->boundedQueryInteger($request->query['limit'] ?? '100', 'limit', 1, 100);
        return [$offset, $limit];
    }

    private function boundedQueryInteger(mixed $value, string $name, int $minimum, int $maximum): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new ApiException(400, ucfirst($name) . ' is invalid.');
        }
        $normalized = (string) $value;
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $normalized) !== 1) {
            throw new ApiException(400, ucfirst($name) . ' is invalid.');
        }
        $number = (int) $normalized;
        if ($number < $minimum || $number > $maximum) {
            throw new ApiException(400, ucfirst($name) . ' is outside the allowed range.');
        }
        return $number;
    }

    private function adminModeFromQuery(HttpRequest $request): ?string
    {
        $mode = $request->query['mode'] ?? 'all';
        if ($mode === 'all') return null;
        if ($mode !== 'normal' && $mode !== 'zen') {
            throw new ApiException(400, 'Mode must be all, normal, or zen.');
        }
        return $mode;
    }

    private function adminStatusFromQuery(HttpRequest $request): ?string
    {
        $status = $request->query['status'] ?? 'all';
        if ($status === 'all') return null;
        if (!is_string($status) || !in_array(
            $status,
            ['legacy', 'verified', 'review', 'quarantined', 'deleted'],
            true,
        )) {
            throw new ApiException(400, 'Verification status is invalid.');
        }
        return $status;
    }

    private function adminOperation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ApiException $error) {
            throw $error;
        } catch (\InvalidArgumentException $error) {
            throw new ApiException(400, $error->getMessage());
        }
    }

    private function modeFromQuery(HttpRequest $request): string
    {
        $mode = $request->query['mode'] ?? 'normal';
        if ($mode !== 'normal' && $mode !== 'zen') {
            throw new ApiException(400, 'Mode must be normal or zen.');
        }
        return $mode;
    }
}
