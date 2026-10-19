<?php

namespace Modules\Leave\Services;

use Carbon\CarbonImmutable;

/**
 * Leave arithmetic from facts only (no database). Amounts are half days.
 *
 * - The leave year starts on the first of leave.year_start_month; it is
 *   named after the calendar year it starts in.
 * - A request counts each day of its range (weekends and holidays left out
 *   unless the type counts them) as two halves; starting in the afternoon or
 *   ending at noon takes one half off that end.
 * - Yearly leave comes at the year's start, for joiners a share for the
 *   months left (the joining month included); monthly leave a twelfth for
 *   each month begun; earned leave one day for every so many days at work.
 * - At most the type's carry limit goes into the next year.
 */
final class LeavePolicy
{
    public static function yearOf(CarbonImmutable $day, int $startMonth): int
    {
        return $day->month >= $startMonth ? $day->year : $day->year - 1;
    }

    public static function yearStart(int $year, int $startMonth): CarbonImmutable
    {
        return CarbonImmutable::create($year, max(1, min(12, $startMonth)), 1, 0, 0, 0, 'UTC');
    }

    public static function yearEnd(int $year, int $startMonth): CarbonImmutable
    {
        return self::yearStart($year, $startMonth)->addYear()->subDay();
    }

    /**
     * The counted days of a range, in order, with their halves (2, or 1 at a half end).
     *
     * @param  callable(CarbonImmutable): bool  $isOff
     * @return list<array{date: string, halves: int}>
     */
    public static function days(CarbonImmutable $from, CarbonImmutable $to, bool $fromHalf, bool $toHalf, callable $isOff, bool $countsDaysOff): array
    {
        $days = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if (! $countsDaysOff && $isOff($day)) {
                continue;
            }
            $halves = 2;
            if ($fromHalf && $day->equalTo($from)) {
                $halves--;
            }
            if ($toHalf && $day->equalTo($to)) {
                $halves--;
            }
            if ($halves > 0) {
                $days[] = ['date' => $day->toDateString(), 'halves' => $halves];
            }
        }

        return $days;
    }

    /** Yearly leave: all of it, or for someone who joined during the year the months left. */
    public static function yearlyShare(int $allowance, CarbonImmutable $joined, int $year, int $startMonth): int
    {
        $start = self::yearStart($year, $startMonth);
        if ($joined->lessThanOrEqualTo($start)) {
            return $allowance;
        }
        if ($joined->greaterThan(self::yearEnd($year, $startMonth))) {
            return 0;
        }
        $months = 12 - (($joined->year - $start->year) * 12 + $joined->month - $start->month);

        return intdiv($allowance * $months, 12);
    }

    /** Monthly leave given for the months begun so far in the year (from joining). */
    public static function monthlyTarget(int $allowance, CarbonImmutable $joined, CarbonImmutable $today, int $year, int $startMonth): int
    {
        $start = self::yearStart($year, $startMonth);
        $from = $joined->greaterThan($start) ? $joined->startOfMonth() : $start;
        if ($today->lessThan($from)) {
            return 0;
        }
        $months = min(12, ($today->year - $from->year) * 12 + $today->month - $from->month + 1);

        return intdiv($allowance * $months, 12);
    }

    /** Earned leave: one day (two halves) for every $perDay days at work. */
    public static function earnedTarget(int $daysAtWork, int $perDay): int
    {
        return $perDay <= 0 ? 0 : intdiv(max(0, $daysAtWork), $perDay) * 2;
    }

    /** What goes into the next year: the balance left, up to the limit. */
    public static function carried(int $balance, int $carryMax): int
    {
        return max(0, min($balance, $carryMax));
    }

    /**
     * Which counted days are paid: the paid halves first, then the rest without pay.
     *
     * @param  list<array{date: string, halves: int}>  $days
     * @return array<string, array{paid: bool, half: bool}> By date.
     */
    public static function payByDay(array $days, int $paidHalves): array
    {
        $result = [];
        $left = $paidHalves;
        foreach ($days as $day) {
            $paid = $left >= $day['halves'];
            $left = max(0, $left - $day['halves']);
            $result[$day['date']] = ['paid' => $paid, 'half' => $day['halves'] === 1];
        }

        return $result;
    }
}
