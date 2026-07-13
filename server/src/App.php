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
        private readonly RunSubmissionService $runs,
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
            $request->guardSameOriginMutation();
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
            $request->guardSameOriginMutation();
            $this->session->logout();
            JsonResponse::send(200, [
                'authenticated' => false,
                'googleClientId' => $this->config->googleClientId,
                'season' => ['id' => $this->config->seasonId, 'name' => $this->config->seasonName],
                'profile' => null,
                'ranks' => null,
            ]);
        }

        if ($request->path === '/api/profile' && ($request->method === 'GET' || $request->method === 'PATCH')) {
            $profile = $this->requirePlayer();
            if ($request->method === 'PATCH') {
                $request->guardSameOriginMutation();
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
            $request->guardSameOriginMutation();
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

        if ($request->path === '/api/leaderboard' && $request->method === 'GET') {
            $mode = $this->modeFromQuery($request);
            $playerId = $this->session->playerId();
            if ($playerId !== null && $this->players->find($playerId) === null) {
                $this->session->logout();
                $playerId = null;
            }
            JsonResponse::send(200, $this->leaderboard->payload($mode, $playerId));
        }

        if ($request->path === '/api/leaderboard' && $request->method === 'POST') {
            $request->guardSameOriginMutation();
            $profile = $this->requirePlayer();
            if (($profile['nicknameConfirmed'] ?? false) !== true) {
                throw new ApiException(409, 'Choose a public nickname before saving a score.');
            }
            $this->session->enforceScoreRateLimit();
            $score = ScoreSubmission::fromArray($request->json());
            $result = $this->runs->submit($profile['id'], $score);
            JsonResponse::send($result['duplicate'] ? 200 : 201, $result);
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

        return [
            'authenticated' => $profile !== null,
            'googleClientId' => $this->config->googleClientId,
            'season' => ['id' => $this->config->seasonId, 'name' => $this->config->seasonName],
            'profile' => $profile,
            'ranks' => $profile === null ? null : $this->leaderboard->rankings($profile['id']),
        ];
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

    private function modeFromQuery(HttpRequest $request): string
    {
        $mode = $request->query['mode'] ?? 'normal';
        if ($mode !== 'normal' && $mode !== 'zen') {
            throw new ApiException(400, 'Mode must be normal or zen.');
        }
        return $mode;
    }
}
