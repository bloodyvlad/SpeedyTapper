<?php

declare(strict_types=1);

namespace SpeedyTapper;

/**
 * Canonical result derived by RunProofValidator.
 *
 * None of these scoring fields are accepted as client authority. fromArray()
 * accepts only a versioned proof and replays it before constructing this value.
 */
final readonly class ScoreSubmission
{
    public const DODGE_POINTS = 550;
    public const ZEN_DURATION_MS = 180_000;
    public const MAX_MULTIPLIER = 5;

    public function __construct(
        public string $runId,
        public string $mode,
        public int $score,
        public int $reactionBasePoints,
        public int $multiplierBonusPoints,
        public int $maxMultiplier,
        public int $multiplierOneHits,
        public int $multiplierTwoHits,
        public int $multiplierThreeHits,
        public int $multiplierFourHits,
        public int $multiplierFiveHits,
        public int $multiplierOneBasePoints,
        public int $multiplierTwoBasePoints,
        public int $multiplierThreeBasePoints,
        public int $multiplierFourBasePoints,
        public int $multiplierFiveBasePoints,
        public int $hits,
        public int $misses,
        public int $dodges,
        public int $survivalMs,
        public ?int $fastestReactionMs,
        public ?int $averageReactionMs,
        public int $godlikeCount,
        public int $perfectCount,
        public int $greatCount,
        public int $goodCount,
        public string $proofHash,
        public int $riskScore,
        public string $riskLevel,
        public array $riskFlags,
    ) {
        if (strlen($proofHash) !== 32) {
            throw new \InvalidArgumentException('Proof hashes must be 32-byte binary SHA-256 values.');
        }
    }

    public static function fromArray(array $input): self
    {
        return (new RunProofValidator())->validate(RunProof::fromArray($input));
    }

    public function payloadHash(): string
    {
        $payload = json_encode([
            'runId' => $this->runId,
            'mode' => $this->mode,
            'score' => $this->score,
            'reactionBasePoints' => $this->reactionBasePoints,
            'multiplierBonusPoints' => $this->multiplierBonusPoints,
            'maxMultiplier' => $this->maxMultiplier,
            'multiplierHitCounts' => [
                'one' => $this->multiplierOneHits,
                'two' => $this->multiplierTwoHits,
                'three' => $this->multiplierThreeHits,
                'four' => $this->multiplierFourHits,
                'five' => $this->multiplierFiveHits,
            ],
            'multiplierBasePoints' => [
                'one' => $this->multiplierOneBasePoints,
                'two' => $this->multiplierTwoBasePoints,
                'three' => $this->multiplierThreeBasePoints,
                'four' => $this->multiplierFourBasePoints,
                'five' => $this->multiplierFiveBasePoints,
            ],
            'hits' => $this->hits,
            'misses' => $this->misses,
            'dodges' => $this->dodges,
            'survivalMs' => $this->survivalMs,
            'fastestReactionMs' => $this->fastestReactionMs,
            'averageReactionMs' => $this->averageReactionMs,
            'speedRatings' => [
                'godlike' => $this->godlikeCount,
                'perfect' => $this->perfectCount,
                'great' => $this->greatCount,
                'good' => $this->goodCount,
            ],
            'proofHash' => bin2hex($this->proofHash),
            'riskScore' => $this->riskScore,
            'riskLevel' => $this->riskLevel,
            'riskFlags' => $this->riskFlags,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $payload, true);
    }

    public function isBetterThan(array $current): bool
    {
        $scoreDifference = $this->score <=> (int) $current['score'];
        if ($scoreDifference !== 0) {
            return $scoreDifference > 0;
        }
        if ($this->mode === 'normal') {
            $durationDifference = $this->survivalMs <=> (int) $current['duration_ms'];
            if ($durationDifference !== 0) {
                return $durationDifference > 0;
            }
        }

        return $this->hits > (int) $current['correct_taps'];
    }

    public function withRiskFlag(string $flag, int $addedScore = 100): self
    {
        if (in_array($flag, $this->riskFlags, true)) return $this;
        $riskFlags = [...$this->riskFlags, $flag];
        $riskScore = min(200, $this->riskScore + max(0, $addedScore));
        return new self(
            runId: $this->runId,
            mode: $this->mode,
            score: $this->score,
            reactionBasePoints: $this->reactionBasePoints,
            multiplierBonusPoints: $this->multiplierBonusPoints,
            maxMultiplier: $this->maxMultiplier,
            multiplierOneHits: $this->multiplierOneHits,
            multiplierTwoHits: $this->multiplierTwoHits,
            multiplierThreeHits: $this->multiplierThreeHits,
            multiplierFourHits: $this->multiplierFourHits,
            multiplierFiveHits: $this->multiplierFiveHits,
            multiplierOneBasePoints: $this->multiplierOneBasePoints,
            multiplierTwoBasePoints: $this->multiplierTwoBasePoints,
            multiplierThreeBasePoints: $this->multiplierThreeBasePoints,
            multiplierFourBasePoints: $this->multiplierFourBasePoints,
            multiplierFiveBasePoints: $this->multiplierFiveBasePoints,
            hits: $this->hits,
            misses: $this->misses,
            dodges: $this->dodges,
            survivalMs: $this->survivalMs,
            fastestReactionMs: $this->fastestReactionMs,
            averageReactionMs: $this->averageReactionMs,
            godlikeCount: $this->godlikeCount,
            perfectCount: $this->perfectCount,
            greatCount: $this->greatCount,
            goodCount: $this->goodCount,
            proofHash: $this->proofHash,
            riskScore: $riskScore,
            riskLevel: $riskScore >= 100 ? 'high' : ($riskScore >= 40 ? 'elevated' : 'low'),
            riskFlags: $riskFlags,
        );
    }
}
