<?php

namespace Modules\EducationExams\Services;

/**
 * The arithmetic of marks and grades, with integers only (marks in
 * hundredths, percentages in basis points, grade points in hundredths,
 * half up) and no database, so it can be tested on its own.
 */
final class ExamMath
{
    /** Marks as a percentage of full marks in basis points: 66 of 75 -> 8800 (88 %), half up. */
    public static function percentBp(int $marksCenti, int $fullCenti): int
    {
        if ($fullCenti <= 0) {
            return 0;
        }

        return intdiv($marksCenti * 10000 + intdiv($fullCenti, 2), $fullCenti);
    }

    /**
     * The band a percentage falls in: the one with the highest start at or
     * below it.
     *
     * @param  list<array{min_percent_bp: int, letter: string, point_centi: int, is_pass: bool}>  $bands
     * @return array{min_percent_bp: int, letter: string, point_centi: int, is_pass: bool}|null
     */
    public static function band(array $bands, int $percentBp): ?array
    {
        $best = null;
        foreach ($bands as $band) {
            if ($band['min_percent_bp'] <= $percentBp && ($best === null || $band['min_percent_bp'] > $best['min_percent_bp'])) {
                $best = $band;
            }
        }

        return $best;
    }

    /**
     * Whether bands make a whole scheme: one starts at 0 %, no two start at
     * the same percentage, every start is between 0 and 100 %.
     *
     * @param  list<array{min_percent_bp: int}>  $bands
     */
    public static function bandsComplete(array $bands): bool
    {
        $starts = array_map(fn (array $band) => (int) $band['min_percent_bp'], $bands);

        return in_array(0, $starts, true) && count($starts) === count(array_unique($starts))
            && min($starts) >= 0 && max($starts) <= 10000;
    }

    /** "82.5" for 8250 hundredths (marks, points), no trailing zeros. */
    public static function centiText(int $centi): string
    {
        $whole = intdiv(abs($centi), 100);
        $rest = rtrim(str_pad((string) (abs($centi) % 100), 2, '0', STR_PAD_LEFT), '0');

        return ($centi < 0 ? '-' : '').$whole.($rest === '' ? '' : '.'.$rest);
    }
}
