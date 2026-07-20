<?php

declare(strict_types=1);

namespace SpeedyTapper;

final readonly class GameCenterIdentity
{
    public function __construct(
        public string $teamPlayerId,
        public string $assertionHash,
        public int $timestampMilliseconds,
    ) {
        if (strlen($this->assertionHash) !== 32) {
            throw new \InvalidArgumentException('Game Center assertion hash must contain 32 bytes.');
        }
    }
}
