<?php

declare(strict_types=1);

namespace SpeedyTapper;

interface GameCenterSubmissionClient
{
    public function submitLeaderboard(
        string $scopedPlayerId,
        int $score,
        bool $preReleased,
    ): string;

    public function submitAchievement(
        string $scopedPlayerId,
        string $achievementId,
        bool $preReleased,
    ): string;
}
