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

    /**
     * A student's result in one paper: marks (absent parts count 0), the
     * percentage, the band, and whether it passes: every part with a pass
     * mark met, the paper's pass mark met, a passing band, not absent from all.
     *
     * @param  list<array{full_marks_centi: int, pass_marks_centi: ?int, marks_centi: ?int, absent: bool}>  $parts
     * @param  list<array{min_percent_bp: int, letter: string, point_centi: int, is_pass: bool}>  $bands
     * @return array{marks_centi: int, full_marks_centi: int, percent_bp: int, letter: ?string, point_centi: int, passed: bool, absent: bool}
     */
    public static function subject(array $parts, int $passCenti, array $bands): array
    {
        $marks = 0;
        $full = 0;
        $partsPass = true;
        $absent = $parts !== [];
        foreach ($parts as $part) {
            $full += $part['full_marks_centi'];
            $marks += $part['absent'] ? 0 : (int) $part['marks_centi'];
            $absent = $absent && $part['absent'];
            if ($part['pass_marks_centi'] !== null && ($part['absent'] || (int) $part['marks_centi'] < $part['pass_marks_centi'])) {
                $partsPass = false;
            }
        }
        $percent = self::percentBp($marks, $full);
        $band = self::band($bands, $percent);
        $passed = ! $absent && $partsPass && $marks >= $passCenti && ($band['is_pass'] ?? false);
        // Failing a part or the paper's pass mark is a fail whatever the percentage: the failing band.
        if (! $passed && ($band['is_pass'] ?? false)) {
            $band = self::band($bands, 0);
        }

        return ['marks_centi' => $marks, 'full_marks_centi' => $full, 'percent_bp' => $percent, 'letter' => $band['letter'] ?? null,
            'point_centi' => $passed ? (int) ($band['point_centi'] ?? 0) : 0, 'passed' => $passed, 'absent' => $absent];
    }

    /**
     * The overall grade point: the average point of the counted subjects
     * (compulsory and elective), by subject or weighted by credits; an
     * optional ("4th") subject adds what it earns above the bonus line when
     * passed; never above the scale's top. Failing a counted subject makes
     * the whole result fail (point 0) when $failAny.
     *
     * @param  list<array{kind: string, point_centi: int, credits_centi: int, passed: bool}>  $subjects
     * @return array{gpa_centi: int, passed: bool, failed: int}
     */
    public static function gpa(array $subjects, int $maxCenti, ?int $bonusAboveCenti, bool $byCredits, bool $failAny = true): array
    {
        $counted = array_values(array_filter($subjects, fn (array $subject) => $subject['kind'] !== 'optional'));
        $failed = count(array_filter($counted, fn (array $subject) => ! $subject['passed']));
        if ($counted === [] || ($failAny && $failed > 0)) {
            return ['gpa_centi' => 0, 'passed' => $counted !== [] && $failed === 0, 'failed' => $failed];
        }
        $weights = array_map(fn (array $subject) => $byCredits ? max(0, $subject['credits_centi']) : 100, $counted);
        $weight = array_sum($weights);
        if ($weight === 0) {
            return ['gpa_centi' => 0, 'passed' => $failed === 0, 'failed' => $failed];
        }
        $sum = 0;
        foreach ($counted as $index => $subject) {
            $sum += $subject['point_centi'] * $weights[$index];
        }
        // The optional subject's bonus counts as one more subject's worth of points spread over the counted ones.
        if ($bonusAboveCenti !== null) {
            foreach ($subjects as $subject) {
                if ($subject['kind'] === 'optional' && $subject['passed']) {
                    $sum += max(0, $subject['point_centi'] - $bonusAboveCenti) * 100;
                }
            }
        }
        $gpa = intdiv($sum + intdiv($weight, 2), $weight);

        return ['gpa_centi' => $maxCenti > 0 ? min($gpa, $maxCenti) : $gpa, 'passed' => $failed === 0, 'failed' => $failed];
    }

    /** The letter of an overall grade point: the band with the highest point at or below it. */
    public static function letterFor(array $bands, int $gpaCenti, bool $passed): ?string
    {
        if (! $passed) {
            return self::band($bands, 0)['letter'] ?? null;
        }
        $best = null;
        foreach ($bands as $band) {
            if ($band['is_pass'] && $band['point_centi'] <= $gpaCenti && ($best === null || $band['point_centi'] > $best['point_centi'])) {
                $best = $band;
            }
        }

        return $best['letter'] ?? null;
    }

    /**
     * A weighted percentage over several exams (weights in basis points);
     * missing exams leave their weight out.
     *
     * @param  list<array{percent_bp: int, weight_bp: int}>  $parts
     */
    public static function weightedPercent(array $parts): int
    {
        $weight = array_sum(array_column($parts, 'weight_bp'));
        if ($weight === 0) {
            return 0;
        }
        $sum = 0;
        foreach ($parts as $part) {
            $sum += $part['percent_bp'] * $part['weight_bp'];
        }

        return intdiv($sum + intdiv($weight, 2), $weight);
    }

    /**
     * Merit positions: passed first, then the higher grade point, then the
     * higher marks; equal results share a position (1, 2, 2, 4).
     *
     * @param  array<string, array{passed: bool, gpa_centi: ?int, marks_centi: int}>  $results  By key.
     * @return array<string, int>
     */
    public static function ranks(array $results): array
    {
        $order = array_keys($results);
        usort($order, fn ($a, $b) => [(int) $results[$b]['passed'], (int) $results[$b]['gpa_centi'], $results[$b]['marks_centi']]
            <=> [(int) $results[$a]['passed'], (int) $results[$a]['gpa_centi'], $results[$a]['marks_centi']]);
        $ranks = [];
        $previous = null;
        foreach ($order as $index => $key) {
            $score = [(int) $results[$key]['passed'], (int) $results[$key]['gpa_centi'], $results[$key]['marks_centi']];
            $ranks[$key] = $score === $previous ? $ranks[$order[$index - 1]] : $index + 1;
            $previous = $score;
        }

        return $ranks;
    }

    /** "82.5" for 8250 hundredths (marks, points), no trailing zeros. */
    public static function centiText(int $centi): string
    {
        $whole = intdiv(abs($centi), 100);
        $rest = rtrim(str_pad((string) (abs($centi) % 100), 2, '0', STR_PAD_LEFT), '0');

        return ($centi < 0 ? '-' : '').$whole.($rest === '' ? '' : '.'.$rest);
    }
}
