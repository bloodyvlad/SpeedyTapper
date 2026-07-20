<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class App
{
    public function __construct(
        private readonly Config $config,
        private readonly PlayerRepository $players,
        private readonly PetShopService $pets,
        private readonly ThemeShopService $themes,
        private readonly LeaderboardRepository $leaderboard,
        private readonly RunAttemptService $attempts,
        private readonly AchievementService $achievements,
        private readonly RunSubmissionService $runs,
        private readonly LeaderboardModerationService $moderation,
        private readonly StoreKitAccountRepository $storeKitAccounts,
        private readonly StoreKitService $storeKit,
        private readonly AppStoreNotificationService $appStoreNotifications,
        private readonly AccountDeletionService $accountDeletion,
        private readonly SessionStore $session,
        private readonly PlayerIdentityService $identities,
        private readonly GoogleIdentityVerifier $google,
        private readonly AppleIdentityVerifier $apple,
        private readonly ?AppleAuthorizationCodeClient $appleTokens,
        private readonly ?AppleCredentialRepository $appleCredentials,
        private readonly GameCenterIdentityVerifier $gameCenter,
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

        if ($request->method === 'GET' && $request->path === '/api/top-scores') {
            JsonResponse::send(
                200,
                $this->leaderboard->topPayload($this->modeFromQuery($request)),
                ['Cache-Control' => 'public, max-age=5, s-maxage=10, stale-while-revalidate=30'],
            );
        }

        if ($request->method === 'POST' && $request->path === '/api/auth/apple/challenge') {
            $this->guardMutation($request);
            if (!$this->config->appleSignInIsConfigured()) {
                throw new ApiException(503, 'Apple sign-in is not configured.');
            }
            $body = $request->json();
            $this->requireOnlyFields($body, ['intent'], 'Apple sign-in challenge');
            $intent = $body['intent'] ?? null;
            if (!is_string($intent) || !in_array($intent, ['login', 'register', 'link', 'reauth'], true)) {
                throw new ApiException(400, 'Apple sign-in intent must be login, register, link, or reauth.');
            }
            $playerId = $this->session->playerId();
            if (in_array($intent, ['login', 'register'], true) && $playerId !== null) {
                throw new ApiException(409, 'Log out before signing in to a different profile.');
            }
            if (in_array($intent, ['link', 'reauth'], true)) {
                $this->requirePlayer();
                if ($intent === 'link') {
                    $this->session->requireRecentPrimaryAuthentication();
                }
            }
            JsonResponse::send(201, [
                'appleSignIn' => $this->session->issueAppleChallenge(
                    $intent,
                    $this->config->appleSignInClientId
                        ?? throw new ApiException(503, 'Apple sign-in is not configured.'),
                ),
            ]);
        }

        if ($request->method === 'POST' && $request->path === '/api/auth/apple') {
            $this->guardMutation($request);
            $body = $request->json();
            $this->requireOnlyFields(
                $body,
                ['challengeId', 'state', 'identityToken', 'authorizationCode'],
                'Apple sign-in',
            );
            $challenge = $this->session->consumeAppleChallenge(
                $body['challengeId'] ?? null,
                $body['state'] ?? null,
            );
            $identityToken = $body['identityToken'] ?? null;
            if (!is_string($identityToken)) {
                throw new ApiException(400, 'Apple identity token is required.');
            }
            $authorizationCode = $body['authorizationCode'] ?? null;
            if (!is_string($authorizationCode)) {
                throw new ApiException(400, 'Apple authorization code is required.');
            }
            $currentPlayerId = $this->session->playerId();
            if (in_array($challenge['intent'], ['link', 'reauth'], true)) {
                if ($currentPlayerId === null) {
                    throw new ApiException(401, 'Sign in before linking or reauthenticating.');
                }
                if ($challenge['intent'] === 'link') {
                    $this->session->requireRecentPrimaryAuthentication();
                }
            }
            $this->session->close();
            $identity = $this->apple->verify(
                $identityToken,
                $challenge['nonce'],
                $challenge['audience'],
            );
            $tokenClient = $this->appleTokens
                ?? throw new ApiException(503, 'Apple sign-in is not configured.');
            $credentials = $this->appleCredentials
                ?? throw new ApiException(503, 'Apple sign-in is not configured.');
            $exchange = $tokenClient->exchange($authorizationCode);
            $exchangedIdentity = $this->apple->verify(
                $exchange->identityToken,
                $challenge['nonce'],
                $challenge['audience'],
            );
            if (
                !hash_equals($identity->subject, $exchangedIdentity->subject)
                || !hash_equals($identity->audience, $exchangedIdentity->audience)
            ) {
                throw new ApiException(401, 'Apple authorization code belongs to a different identity.');
            }
            $intent = $challenge['intent'];
            $activePlayerId = $this->session->playerId();
            if (in_array($intent, ['login', 'register'], true)) {
                if ($currentPlayerId !== null || $activePlayerId !== null) {
                    throw new ApiException(409, 'Log out before signing in to a different profile.');
                }
                $resolution = $this->identities->loginOrRegister(
                    PlayerIdentityService::PROVIDER_APPLE,
                    $identity->subject,
                    $intent === 'register',
                    function (string $playerId) use ($credentials, $identity, $exchange): void {
                        $credentials->storeOrRetainInCurrentTransaction(
                            $playerId,
                            $identity->subject,
                            $exchange->refreshToken,
                        );
                    },
                );
                $this->session->login(
                    $resolution['playerId'],
                    PlayerIdentityService::PROVIDER_APPLE,
                );
            } else {
                if (
                    $currentPlayerId === null
                    || $activePlayerId === null
                    || !hash_equals($currentPlayerId, $activePlayerId)
                ) {
                    throw new ApiException(401, 'The sign-in session changed. Start again.');
                }
                if ($intent === 'link') {
                    $this->session->requireRecentPrimaryAuthentication();
                }
                $profile = $this->players->find($activePlayerId)
                    ?? throw new ApiException(401, 'Sign in again to continue.');
                if ($intent === 'link') {
                    $this->identities->linkPrimary(
                        $profile['id'],
                        PlayerIdentityService::PROVIDER_APPLE,
                        $identity->subject,
                        function (string $playerId) use ($credentials, $identity, $exchange): void {
                            $credentials->storeOrRetainInCurrentTransaction(
                                $playerId,
                                $identity->subject,
                                $exchange->refreshToken,
                            );
                        },
                    );
                } else {
                    $this->identities->reauthenticate(
                        $profile['id'],
                        PlayerIdentityService::PROVIDER_APPLE,
                        $identity->subject,
                        function (string $playerId) use ($credentials, $identity, $exchange): void {
                            $credentials->storeOrRetainInCurrentTransaction(
                                $playerId,
                                $identity->subject,
                                $exchange->refreshToken,
                            );
                        },
                    );
                }
                $this->session->markPrimaryAuthenticated(PlayerIdentityService::PROVIDER_APPLE);
            }
            JsonResponse::send(200, $this->sessionPayload());
        }

        if ($request->method === 'POST' && $request->path === '/api/auth/google') {
            $this->guardMutation($request);
            $body = $request->json();
            $this->requireOnlyFields($body, ['credential', 'intent'], 'Google sign-in');
            $credential = $body['credential'] ?? null;
            if (!is_string($credential)) {
                throw new ApiException(400, 'Google credential is required.');
            }
            $identity = $this->google->verify($credential);
            $intent = $body['intent'] ?? 'login_or_register';
            if (!is_string($intent) || !in_array($intent, ['login', 'register', 'login_or_register', 'reauth'], true)) {
                throw new ApiException(400, 'Google sign-in intent is invalid.');
            }
            $currentPlayerId = $this->session->playerId();
            if ($currentPlayerId === null) {
                if ($intent === 'reauth') {
                    throw new ApiException(401, 'Sign in before reauthenticating.');
                }
                $resolution = $this->identities->loginOrRegister(
                    PlayerIdentityService::PROVIDER_GOOGLE,
                    $identity->subject,
                    $intent !== 'login',
                );
                $this->session->login(
                    $resolution['playerId'],
                    PlayerIdentityService::PROVIDER_GOOGLE,
                );
            } else {
                if ($intent !== 'reauth' && $intent !== 'login_or_register') {
                    throw new ApiException(409, 'Use the explicit link flow to add another sign-in method.');
                }
                $this->identities->reauthenticate(
                    $currentPlayerId,
                    PlayerIdentityService::PROVIDER_GOOGLE,
                    $identity->subject,
                );
                $this->session->markPrimaryAuthenticated(PlayerIdentityService::PROVIDER_GOOGLE);
            }
            JsonResponse::send(200, $this->sessionPayload());
        }

        if ($request->method === 'POST' && $request->path === '/api/profile/identities/google') {
            $this->guardMutation($request);
            $profile = $this->requirePlayer();
            $this->session->requireRecentPrimaryAuthentication();
            $body = $request->json();
            $this->requireOnlyFields($body, ['credential'], 'Google identity link');
            $credential = $body['credential'] ?? null;
            if (!is_string($credential)) {
                throw new ApiException(400, 'Google credential is required.');
            }
            $identity = $this->google->verify($credential);
            $this->identities->linkPrimary(
                $profile['id'],
                PlayerIdentityService::PROVIDER_GOOGLE,
                $identity->subject,
            );
            $this->session->markPrimaryAuthenticated(PlayerIdentityService::PROVIDER_GOOGLE);
            JsonResponse::send(200, $this->sessionPayload());
        }

        if ($request->method === 'POST' && $request->path === '/api/profile/game-center/challenge') {
            $this->guardMutation($request);
            $this->requireOnlyFields($request->json(), [], 'Game Center link challenge');
            $this->requirePlayer();
            $this->session->requireRecentPrimaryAuthentication();
            JsonResponse::send(201, [
                'gameCenter' => $this->session->issueGameCenterChallenge(),
            ]);
        }

        if ($request->method === 'POST' && $request->path === '/api/profile/game-center') {
            $this->guardMutation($request);
            $profile = $this->requirePlayer();
            $this->session->requireRecentPrimaryAuthentication();
            $body = $request->json();
            $this->requireOnlyFields(
                $body,
                ['challengeId', 'teamPlayerId', 'publicKeyUrl', 'signature', 'salt', 'timestamp'],
                'Game Center link',
            );
            $challenge = $this->session->consumeGameCenterChallenge($body['challengeId'] ?? null);
            $identity = $this->gameCenter->verify(
                $body['teamPlayerId'] ?? null,
                $body['publicKeyUrl'] ?? null,
                $body['signature'] ?? null,
                $body['salt'] ?? null,
                $body['timestamp'] ?? null,
                $challenge['issuedAtMilliseconds'],
            );
            $result = $this->identities->linkGameCenter($profile['id'], $identity);
            JsonResponse::send(200, [
                'profile' => $this->players->find($profile['id'])
                    ?? throw new ApiException(401, 'Sign in again to continue.'),
                'identityBindings' => $this->identities->bindings($profile['id']),
                'gameCenter' => ['linked' => true, 'newlyLinked' => $result['linked']],
            ]);
        }

        if ($request->method === 'POST' && $request->path === '/api/logout') {
            $this->guardMutation($request);
            $this->session->logout();
            JsonResponse::send(200, $this->sessionPayload());
        }

        if ($request->method === 'POST' && in_array(
            $request->path,
            ['/api/storekit/transactions', '/api/mobile/v1/storekit/transactions'],
            true,
        )) {
            $this->guardMutation($request);
            $profile = $this->requirePlayer();
            $body = $request->json();
            $unknown = array_diff(array_keys($body), ['signedTransaction', 'appAccountToken']);
            if ($unknown !== []) {
                throw new ApiException(400, 'StoreKit requests contain unsupported client fields.');
            }
            JsonResponse::send(200, $this->storeKit->submit(
                $profile['id'],
                $body['signedTransaction'] ?? null,
                $body['appAccountToken'] ?? null,
            ));
        }

        if ($request->method === 'POST' && $request->path === '/api/app-store/notifications/v2') {
            $body = $request->json();
            $unknown = array_diff(array_keys($body), ['signedPayload']);
            if ($unknown !== []) {
                throw new ApiException(400, 'The App Store notification request is invalid.');
            }
            JsonResponse::send(200, $this->appStoreNotifications->receive($body['signedPayload'] ?? null));
        }

        if ($request->method === 'DELETE' && in_array(
            $request->path,
            ['/api/profile', '/api/account', '/api/mobile/v1/account'],
            true,
        )) {
            $this->guardMutation($request);
            $profile = $this->requirePlayer();
            $confirmation = $request->json()['confirmation'] ?? null;
            if (!is_string($confirmation) || !hash_equals('DELETE MY ACCOUNT', $confirmation)) {
                throw new ApiException(400, 'Explicit account-deletion confirmation is required.');
            }
            $this->session->requireRecentPrimaryAuthentication();
            $bindings = $this->identities->bindings($profile['id']);
            if (($bindings['apple'] ?? false) === true) {
                $credentials = $this->appleCredentials
                    ?? throw new ApiException(503, 'Apple account revocation is not configured.');
                $refreshToken = $credentials->refreshTokenForDeletion($profile['id'])
                    ?? throw new ApiException(503, 'Apple account revocation material is missing.');
                $this->session->close();
                ($this->appleTokens
                    ?? throw new ApiException(503, 'Apple account revocation is not configured.'))
                    ->revoke($refreshToken);
            }
            $result = $this->accountDeletion->delete($profile['id']);
            $this->session->logout();
            JsonResponse::send(200, [...$result, 'authenticated' => false]);
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
                'identityBindings' => $this->identities->bindings($profile['id']),
                ...$this->storeKitAccounts->state($profile['id']),
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
                ?? throw new ApiException(401, 'Sign in again to continue.');
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
                ?? throw new ApiException(401, 'Sign in again to continue.');
            JsonResponse::send(200, [
                'profile' => $profile,
                'pet' => [
                    'id' => $result['pet']['id'],
                    'visible' => $result['visible'],
                ],
                'coinBalance' => $profile['coins'],
            ]);
        }

        if ($request->method === 'GET' && $request->path === '/api/themes') {
            $playerId = $this->session->playerId();
            $profile = $playerId === null ? null : $this->players->find($playerId);
            if ($playerId !== null && $profile === null) {
                $this->session->logout();
            }
            JsonResponse::send(200, [
                'themes' => ThemeCatalog::all(),
                'profile' => $profile,
                'coinBalance' => $profile['coins'] ?? 0,
            ]);
        }

        if ($request->method === 'POST' && $request->path === '/api/themes/select') {
            $this->guardMutation($request);
            $profile = $this->requirePlayer();
            $result = $this->themes->select($profile['id'], $request->json()['themeId'] ?? null);
            $profile = $this->players->find($profile['id'])
                ?? throw new ApiException(401, 'Sign in again to continue.');
            JsonResponse::send($result['purchased'] ? 201 : 200, [
                'profile' => $profile,
                'theme' => [
                    'id' => $result['theme']['id'],
                    'purchased' => $result['purchased'],
                    'pricePaid' => $result['pricePaid'],
                ],
                'coinBalance' => $profile['coins'],
            ]);
        }

        if ($request->path === '/api/leaderboard' && $request->method === 'GET') {
            $mode = $this->modeFromQuery($request);
            $playerId = $this->session->playerId();
            $this->session->close();
            if ($playerId !== null && $this->players->findRunIdentity($playerId) === null) {
                $this->session->logout();
                $this->session->close();
                $playerId = null;
            }
            JsonResponse::send(200, $this->leaderboard->payload($mode, $playerId));
        }

        if ($request->path === '/api/achievements' && $request->method === 'GET') {
            $playerId = $this->session->playerId();
            $this->session->close();
            if ($playerId !== null && $this->players->findRunIdentity($playerId) === null) {
                $this->session->logout();
                $this->session->close();
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
            [$playerId, $sessionBindingHash] = $this->rankedRunContext(false);
            $body = $request->json();
            JsonResponse::send(201, $this->attempts->start(
                $playerId,
                $sessionBindingHash,
                $body['mode'] ?? null,
                $body['buildId'] ?? null,
            ));
        }

        if ($request->path === '/api/runs/abandon' && $request->method === 'POST') {
            $this->guardMutation($request);
            $sessionBindingHash = $this->session->runBindingHash();
            $this->session->close();
            $this->attempts->abandon(
                $sessionBindingHash,
                $request->json()['runId'] ?? null,
            );
            JsonResponse::send(200, ['abandoned' => true]);
        }

        if ($request->path === '/api/runs/finish' && $request->method === 'POST') {
            $this->guardMutation($request);
            // Count the request before parsing or normalizing its proof so malformed
            // authenticated bodies cannot repeatedly consume replay CPU for free.
            [$playerId, $sessionBindingHash] = $this->rankedRunContext(true);
            $proof = RunProof::fromArray($request->json());
            $result = $this->runs->submit(
                $playerId,
                $sessionBindingHash,
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
        $csrfToken = $this->session->csrfToken();
        $this->session->close();
        $profile = $playerId === null ? null : $this->players->find($playerId);
        if ($playerId !== null && $profile === null) {
            $this->session->logout();
            $csrfToken = $this->session->csrfToken();
            $this->session->close();
        }

        return [
            'authenticated' => $profile !== null,
            'csrfToken' => $csrfToken,
            'googleClientId' => $this->config->googleClientId,
            'appleSignIn' => [
                'enabled' => $this->config->appleSignInIsConfigured(),
                'clientId' => $this->config->appleSignInClientId,
            ],
            'season' => ['id' => $this->config->seasonId, 'name' => $this->config->seasonName],
            'profile' => $profile,
            'identityBindings' => $profile === null
                ? null
                : $this->identities->bindings($profile['id']),
            ...($profile === null ? [
                'wallet' => null,
                'adFree' => false,
                'storeKit' => null,
            ] : $this->storeKitAccounts->state($profile['id'])),
            'ranks' => $profile === null ? null : $this->leaderboard->rankings($profile['id']),
            'achievementSnapshot' => $profile === null
                ? $this->achievements->payload(null)
                : $this->achievements->currentPayload($profile['id'], (int) $profile['coins']),
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
            throw new ApiException(401, 'Sign in to continue.');
        }
        return $profile;
    }

    /** @return array{string, string} */
    private function rankedRunContext(bool $countFinishRequest): array
    {
        $playerId = $this->session->playerId();
        if ($playerId === null) {
            $this->session->close();
            throw new ApiException(401, 'Sign in to continue.');
        }
        if ($countFinishRequest) {
            $this->session->requireRunFinishCapacity();
        }
        $sessionBindingHash = $this->session->runBindingHash();
        $this->session->close();

        $identity = $this->players->findRunIdentity($playerId);
        if ($identity === null) {
            $this->session->logout();
            $this->session->close();
            throw new ApiException(401, 'Sign in to continue.');
        }
        if (($identity['nicknameConfirmed'] ?? false) !== true) {
            throw new ApiException(
                409,
                $countFinishRequest
                    ? 'Choose a public nickname before saving a score.'
                    : 'Choose a public nickname before starting a ranked run.',
            );
        }

        return [$playerId, $sessionBindingHash];
    }

    private function requireAdmin(bool $requireRecentPrimaryAuthentication = false): array
    {
        $profile = $this->requirePlayer();
        if (($profile['isAdmin'] ?? false) !== true) {
            throw new ApiException(403, 'Leaderboard administrator access is required.');
        }
        if ($requireRecentPrimaryAuthentication) {
            $this->session->requireRecentPrimaryAuthentication();
        }
        return $profile;
    }

    /** @param list<string> $allowed */
    private function requireOnlyFields(array $body, array $allowed, string $operation): void
    {
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown !== []) {
            throw new ApiException(400, $operation . ' contains unsupported fields.');
        }
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
