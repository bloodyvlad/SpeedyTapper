<?php

declare(strict_types=1);

namespace SpeedyTapper;

interface GoogleIdentityVerifier
{
    public function verify(string $credential): GoogleIdentity;
}
