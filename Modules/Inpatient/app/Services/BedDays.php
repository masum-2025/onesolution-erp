<?php

namespace Modules\Inpatient\Services;

use Carbon\CarbonImmutable;

/**
 * How many bed days a stay has run, and when each began (rule
 * inpatient.bed_day_starts), in the company's time zone:
 *
 * - midnight: each calendar day from the admission day counts; while the
 *   patient is in, today counts; at discharge the discharge day does not
 *   (a same-day stay is one day).
 * - admission_time: each 24 hours from admission is a day; every started
 *   period counts while the patient is in, and at discharge (at least one).
 */
final class BedDays
{
    public const MIDNIGHT = 'midnight';

    public const ADMISSION_TIME = 'admission_time';

    public static function count(string $rule, CarbonImmutable $admittedAt, CarbonImmutable $until, bool $discharged, string $timezone): int
    {
        $from = $admittedAt->setTimezone($timezone);
        $to = $until->setTimezone($timezone);
        if ($to->lessThan($from)) {
            return 0;
        }
        if ($rule === self::ADMISSION_TIME) {
            $seconds = $to->getTimestamp() - $from->getTimestamp();
            $periods = intdiv($seconds, 86400);

            return max(1, $discharged ? $periods + ($seconds % 86400 > 0 ? 1 : 0) : $periods + 1);
        }
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay(), true);

        return max(1, $discharged ? $days : $days + 1);
    }

    /** When bed day $n (1, 2, …) began: the admission for day 1, then each midnight or each 24 hours. */
    public static function start(string $rule, CarbonImmutable $admittedAt, int $n, string $timezone): CarbonImmutable
    {
        $from = $admittedAt->setTimezone($timezone);
        if ($n <= 1) {
            return $from;
        }

        return $rule === self::ADMISSION_TIME ? $from->addSeconds(86400 * ($n - 1)) : $from->startOfDay()->addDays($n - 1);
    }
}
