<?php

namespace Modules\Appraisals\Services;

/**
 * How a review is scored, in whole numbers per mille (0-1000; no floats):
 * a rating's place on the scale (lowest 0, highest 1000), goals weighed by
 * their weights, competencies counted alike, the two mixed by the cycle's
 * goal share, and the band the score reaches. Rounded half up.
 */
final class Scoring
{
    /** A rating's place on a scale from $low to $high: 0 to 1000. */
    public static function place(int $rating, int $low, int $high): int
    {
        if ($high <= $low) {
            return 1000;
        }
        $rating = max($low, min($high, $rating));

        return self::divide(($rating - $low) * 1000, $high - $low);
    }

    /**
     * Goals: each rating's place weighed by its goal's weight; null with no rated goal.
     *
     * @param  list<array{weight: int, rating: int}>  $goals
     */
    public static function goals(array $goals, int $low, int $high): ?int
    {
        $weights = array_sum(array_column($goals, 'weight'));
        if ($goals === [] || $weights <= 0) {
            return null;
        }
        $sum = 0;
        foreach ($goals as $goal) {
            $sum += $goal['weight'] * self::place($goal['rating'], $low, $high);
        }

        return self::divide($sum, $weights);
    }

    /**
     * Competencies: the places of their ratings, counted alike; null with none rated.
     *
     * @param  list<int>  $ratings
     */
    public static function competencies(array $ratings, int $low, int $high): ?int
    {
        if ($ratings === []) {
            return null;
        }

        return self::divide(array_sum(array_map(fn (int $rating) => self::place($rating, $low, $high), $ratings)), count($ratings));
    }

    /** Both mixed by the goal share (percent); either alone when the other has nothing. */
    public static function overall(?int $goals, ?int $competencies, int $goalShare): ?int
    {
        if ($goals === null || $competencies === null) {
            return $goals ?? $competencies;
        }

        return self::divide($goals * $goalShare + $competencies * (100 - $goalShare), 100);
    }

    /**
     * The first band (highest minimum first) the score reaches.
     *
     * @param  list<array{min: int, key: string}>  $bands
     */
    public static function band(int $score, array $bands): ?string
    {
        usort($bands, fn (array $a, array $b) => $b['min'] <=> $a['min']);
        foreach ($bands as $band) {
            if ($score >= $band['min']) {
                return $band['key'];
            }
        }

        return null;
    }

    /** $a / $b rounded half up (both not negative). */
    private static function divide(int $a, int $b): int
    {
        return intdiv($a * 2 + $b, $b * 2);
    }
}
