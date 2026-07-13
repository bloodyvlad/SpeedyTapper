<?php

declare(strict_types=1);

namespace SpeedyTapper;

final readonly class GoogleIdentity
{
    public function __construct(
        public string $subject,
        public string $suggestedNickname,
    ) {
    }
}
