<?php

declare(strict_types=1);

namespace SpeedyTapper;

use Throwable;

final class GameCenterOutboxWorker
{
    public function __construct(
        private readonly GameCenterPublicationRepository $outbox,
        private readonly GameCenterSubmissionClient $apple,
    ) {
    }

    /** @return array{claimed: int, delivered: int, superseded: int, failed: int} */
    public function run(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Game Center worker limit is invalid.');
        }
        $stats = ['claimed' => 0, 'delivered' => 0, 'superseded' => 0, 'failed' => 0];
        while ($stats['claimed'] < $limit) {
            $job = $this->outbox->claimNext();
            if ($job === null) {
                break;
            }
            $stats['claimed']++;
            try {
                $outcome = $this->outbox->withPlayerPublicationLock(
                    (string) $job['player_id'],
                    function () use ($job): string {
                        $target = $this->outbox->prepareClaimForDelivery($job);
                        if ($target === null) {
                            return 'superseded';
                        }
                        $kind = (string) $job['publication_kind'];
                        if ($kind === 'leaderboard') {
                            $submissionId = $this->apple->submitLeaderboard(
                                $target['scopedPlayerId'],
                                $target['vendorIdentifier'],
                                $target['desiredValue'],
                                (bool) $job['pre_released'],
                            );
                        } elseif ($kind === 'achievement') {
                            $submissionId = $this->apple->submitAchievement(
                                $target['scopedPlayerId'],
                                GameCenterCatalog::achievementIdForVendorIdentifier(
                                    (string) $job['vendor_identifier'],
                                ),
                                (bool) $job['pre_released'],
                            );
                        } else {
                            throw new \RuntimeException(
                                'Unknown Game Center publication kind.',
                            );
                        }
                        return $this->outbox->markSucceeded($job, $submissionId)
                            ? 'delivered'
                            : 'superseded';
                    },
                );
                if ($outcome === 'superseded') {
                    $stats['superseded']++;
                    continue;
                }
                if ($outcome === 'delivered') {
                    $stats['delivered']++;
                } else {
                    throw new \RuntimeException('Unknown Game Center worker outcome.');
                }
            } catch (Throwable $error) {
                $this->outbox->markFailed($job, $error);
                $stats['failed']++;
            }
        }
        return $stats;
    }
}
