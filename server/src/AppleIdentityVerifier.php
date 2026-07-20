<?php

declare(strict_types=1);

namespace SpeedyTapper;

interface AppleIdentityVerifier
{
    public function verify(
        string $identityToken,
        string $expectedNonce,
        string $expectedAudience,
    ): AppleIdentity;
}
