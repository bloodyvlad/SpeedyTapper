<?php

declare(strict_types=1);

namespace SpeedyTapper;

final readonly class ScoreSubmission
{
    public const DODGE_POINTS = 550;
    public const ZEN_DURATION_MS = 180_000;
    public const MAX_MULTIPLIER = 5;
    private const MAX_SCORE = 999_999_999;
    private const MAX_COUNT = 1_000_000;
    private const MAX_DURATION_MS = 7 * 24 * 60 * 60 * 1_000;
    private const MAX_REACTION_MS = 60_000;

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
        public int $dodges,
        public int $survivalMs,
        public ?int $fastestReactionMs,
        public ?int $averageReactionMs,
        public int $godlikeCount,
        public int $perfectCount,
        public int $greatCount,
        public int $goodCount,
    ) {
    }

    public static function fromArray(array $input): self
    {
        $rawRunId = $input['runId'] ?? null;
        if (!is_string($rawRunId) || !Uuid::isValidV4($rawRunId)) {
            throw new ApiException(400, 'Run ID is invalid.');
        }
        $runId = strtolower($rawRunId);

        $mode = $input['mode'] ?? null;
        if ($mode !== 'normal' && $mode !== 'zen') {
            throw new ApiException(400, 'Mode must be normal or zen.');
        }

        $ratings = $input['speedRatings'] ?? null;
        if (!is_array($ratings) || array_is_list($ratings)) {
            throw new ApiException(400, 'Speed ratings are invalid.');
        }

        $score = self::integer($input['score'] ?? null, 'Score', self::MAX_SCORE);
        $reactionBasePoints = self::integer(
            $input['reactionBasePoints'] ?? null,
            'Reaction base points',
            self::MAX_SCORE,
        );
        $multiplierBonusPoints = self::integer(
            $input['multiplierBonusPoints'] ?? null,
            'Multiplier bonus points',
            self::MAX_SCORE,
        );
        $maxMultiplier = self::integerBetween(
            $input['maxMultiplier'] ?? null,
            'Maximum multiplier',
            1,
            self::MAX_MULTIPLIER,
        );
        $multiplierHits = $input['multiplierHitCounts'] ?? null;
        if (!is_array($multiplierHits) || array_is_list($multiplierHits)) {
            throw new ApiException(400, 'Multiplier hit counts are invalid.');
        }
        $multiplierOneHits = self::integer($multiplierHits['one'] ?? null, 'One-times hit count', self::MAX_COUNT);
        $multiplierTwoHits = self::integer($multiplierHits['two'] ?? null, 'Two-times hit count', self::MAX_COUNT);
        $multiplierThreeHits = self::integer($multiplierHits['three'] ?? null, 'Three-times hit count', self::MAX_COUNT);
        $multiplierFourHits = self::integer($multiplierHits['four'] ?? null, 'Four-times hit count', self::MAX_COUNT);
        $multiplierFiveHits = self::integer($multiplierHits['five'] ?? null, 'Five-times hit count', self::MAX_COUNT);
        $multiplierHitCounts = [
            1 => $multiplierOneHits,
            2 => $multiplierTwoHits,
            3 => $multiplierThreeHits,
            4 => $multiplierFourHits,
            5 => $multiplierFiveHits,
        ];
        $multiplierBases = $input['multiplierBasePoints'] ?? null;
        if (!is_array($multiplierBases) || array_is_list($multiplierBases)) {
            throw new ApiException(400, 'Multiplier base points are invalid.');
        }
        $multiplierOneBasePoints = self::integer($multiplierBases['one'] ?? null, 'One-times base points', self::MAX_SCORE);
        $multiplierTwoBasePoints = self::integer($multiplierBases['two'] ?? null, 'Two-times base points', self::MAX_SCORE);
        $multiplierThreeBasePoints = self::integer($multiplierBases['three'] ?? null, 'Three-times base points', self::MAX_SCORE);
        $multiplierFourBasePoints = self::integer($multiplierBases['four'] ?? null, 'Four-times base points', self::MAX_SCORE);
        $multiplierFiveBasePoints = self::integer($multiplierBases['five'] ?? null, 'Five-times base points', self::MAX_SCORE);
        $multiplierBasePoints = [
            1 => $multiplierOneBasePoints,
            2 => $multiplierTwoBasePoints,
            3 => $multiplierThreeBasePoints,
            4 => $multiplierFourBasePoints,
            5 => $multiplierFiveBasePoints,
        ];
        $hits = self::integer($input['hits'] ?? null, 'Tap count', self::MAX_COUNT);
        $dodges = self::integer($input['dodges'] ?? 0, 'Dodge count', self::MAX_COUNT);
        $survivalMs = self::integer($input['survivalMs'] ?? null, 'Survival time', self::MAX_DURATION_MS);
        if ($mode === 'zen' && $survivalMs !== self::ZEN_DURATION_MS) {
            throw new ApiException(400, 'Zen runs must last exactly three minutes.');
        }

        $godlike = self::integer($ratings['godlike'] ?? null, 'Godlike count', self::MAX_COUNT);
        $perfect = self::integer($ratings['perfect'] ?? null, 'Perfect count', self::MAX_COUNT);
        $great = self::integer($ratings['great'] ?? null, 'Great count', self::MAX_COUNT);
        $good = self::integer($ratings['good'] ?? null, 'Good count', self::MAX_COUNT);
        if ($godlike + $perfect + $great + $good !== $hits) {
            throw new ApiException(400, 'Speed ratings must account for every correct tap.');
        }
        if (array_sum($multiplierHitCounts) !== $hits) {
            throw new ApiException(400, 'Multiplier hit counts must account for every correct tap.');
        }

        $highestMultiplierUsed = 1;
        for ($multiplier = self::MAX_MULTIPLIER; $multiplier >= 2; $multiplier--) {
            if ($multiplierHitCounts[$multiplier] > 0) {
                $highestMultiplierUsed = $multiplier;
                break;
            }
        }
        if ($maxMultiplier !== $highestMultiplierUsed) {
            throw new ApiException(400, 'Maximum multiplier does not match the multiplier hit counts.');
        }
        for ($multiplier = 1; $multiplier < $maxMultiplier; $multiplier++) {
            if ($multiplierHitCounts[$multiplier] < 5) {
                throw new ApiException(400, 'Multiplier hit counts skip a required milestone.');
            }
        }
        if ($godlike + $perfect < 5 * ($maxMultiplier - 1)) {
            throw new ApiException(400, 'Maximum multiplier does not match the speed ratings.');
        }
        $requiredEliteHitsAtOne = $maxMultiplier > 1 ? 5 : 0;
        if ($good > $multiplierOneHits - $requiredEliteHitsAtOne) {
            throw new ApiException(400, 'Good reactions cannot be scored above the one-times multiplier.');
        }

        $fastest = self::nullableInteger($input['fastestReactionMs'] ?? null, 'Fastest reaction', self::MAX_REACTION_MS);
        $average = self::nullableInteger($input['averageReactionMs'] ?? null, 'Average reaction', self::MAX_REACTION_MS);
        if (($fastest === null) !== ($average === null)) {
            throw new ApiException(400, 'Reaction statistics must include fastest and average times.');
        }
        if ($hits === 0 && $fastest !== null) {
            throw new ApiException(400, 'Reaction statistics are invalid.');
        }
        if ($hits > 0 && ($fastest === null || $average === null || $fastest > $average)) {
            throw new ApiException(400, 'Reaction statistics are invalid.');
        }

        $validatedReactionBase = 0;
        $validatedMultiplierBonus = 0;
        foreach ($multiplierHitCounts as $multiplier => $count) {
            $basePoints = $multiplierBasePoints[$multiplier];
            if ($basePoints < $count * 100 || $basePoints > $count * 1_000) {
                throw new ApiException(400, 'Multiplier base points do not match their hit counts.');
            }
            $validatedReactionBase += $basePoints;
            $validatedMultiplierBonus += $basePoints * ($multiplier - 1);
        }
        if ($reactionBasePoints !== $validatedReactionBase) {
            throw new ApiException(400, 'Reaction base points do not match the multiplier totals.');
        }
        if ($multiplierBonusPoints !== $validatedMultiplierBonus) {
            throw new ApiException(400, 'Multiplier bonus points are invalid.');
        }

        $expectedScore = $reactionBasePoints
            + $multiplierBonusPoints
            + $dodges * self::DODGE_POINTS;
        if ($score !== $expectedScore) {
            throw new ApiException(400, 'Score does not match the run statistics.');
        }

        return new self(
            runId: $runId,
            mode: $mode,
            score: $score,
            reactionBasePoints: $reactionBasePoints,
            multiplierBonusPoints: $multiplierBonusPoints,
            maxMultiplier: $maxMultiplier,
            multiplierOneHits: $multiplierOneHits,
            multiplierTwoHits: $multiplierTwoHits,
            multiplierThreeHits: $multiplierThreeHits,
            multiplierFourHits: $multiplierFourHits,
            multiplierFiveHits: $multiplierFiveHits,
            multiplierOneBasePoints: $multiplierOneBasePoints,
            multiplierTwoBasePoints: $multiplierTwoBasePoints,
            multiplierThreeBasePoints: $multiplierThreeBasePoints,
            multiplierFourBasePoints: $multiplierFourBasePoints,
            multiplierFiveBasePoints: $multiplierFiveBasePoints,
            hits: $hits,
            dodges: $dodges,
            survivalMs: $survivalMs,
            fastestReactionMs: $fastest,
            averageReactionMs: $average,
            godlikeCount: $godlike,
            perfectCount: $perfect,
            greatCount: $great,
            goodCount: $good,
        );
    }

    public function payloadHash(): string
    {
        $payload = json_encode([
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
        ], JSON_THROW_ON_ERROR);

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

    private static function integer(mixed $value, string $label, int $maximum): int
    {
        if (!is_int($value) || $value < 0 || $value > $maximum) {
            throw new ApiException(400, $label . ' is invalid.');
        }
        return $value;
    }

    private static function integerBetween(
        mixed $value,
        string $label,
        int $minimum,
        int $maximum,
    ): int {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ApiException(400, $label . ' is invalid.');
        }
        return $value;
    }

    private static function nullableInteger(mixed $value, string $label, int $maximum): ?int
    {
        return $value === null ? null : self::integer($value, $label, $maximum);
    }
}
