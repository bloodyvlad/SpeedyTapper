<?php

declare(strict_types=1);

namespace SpeedyTapper;

final readonly class AppleTokenExchange
{
    public function __construct(
        public string $identityToken,
        public ?string $refreshToken,
    ) {
        if ($this->identityToken === '' || strlen($this->identityToken) > 12_288) {
            throw new \InvalidArgumentException('Apple token response identity token is invalid.');
        }
        if (
            $this->refreshToken !== null
            && ($this->refreshToken === '' || strlen($this->refreshToken) > 4_096)
        ) {
            throw new \InvalidArgumentException('Apple token response refresh token is invalid.');
        }
    }
}
