<?php

declare(strict_types=1);

namespace SpeedyTapper;

final readonly class ScoreSubmission
{
    public const DODGE_POINTS = 550;
    public const ZEN_DURATION_MS = 180_000;
    private const MAX_SCORE = 999_999_999;
    private const MAX_COUNT = 1_000_000;
    private const MAX_DURATION_MS = 7 * 24 * 60 * 60 * 1_000;
    private const MAX_REACTION_MS = 60_000;

    public function __construct(
        public string $mode,
        public int $score,
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
        $mode = $input['mode'] ?? null;
        if ($mode !== 'normal' && $mode !== 'zen') {
            throw new ApiException(400, 'Mode must be normal or zen.');
        }

        $ratings = $input['speedRatings'] ?? null;
        if (!is_array($ratings) || array_is_list($ratings)) {
            throw new ApiException(400, 'Speed ratings are invalid.');
        }

        $score = self::integer($input['score'] ?? null, 'Score', self::MAX_SCORE);
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

        $minimumScore = $hits * 100 + $dodges * self::DODGE_POINTS;
        $maximumScore = $hits * 1_000 + $dodges * self::DODGE_POINTS;
        if ($score < $minimumScore || $score > $maximumScore) {
            throw new ApiException(400, 'Score does not match the run statistics.');
        }

        return new self(
            mode: $mode,
            score: $score,
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

    private static function nullableInteger(mixed $value, string $label, int $maximum): ?int
    {
        return $value === null ? null : self::integer($value, $label, $maximum);
    }
}
