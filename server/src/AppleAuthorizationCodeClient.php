<?php

declare(strict_types=1);

namespace SpeedyTapper;

interface AppleAuthorizationCodeClient
{
    public function exchange(string $authorizationCode): AppleTokenExchange;

    public function revoke(string $refreshToken): void;
}
