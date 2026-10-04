<?php

namespace Modules\Attendance\Services;

use Carbon\CarbonImmutable;
use Modules\Attendance\Enums\DayStatus;

/**
 * How one employee's day went, from facts only (no database): when the
 * shift starts and ends (UTC), its break, whether the day is off, the
 * punches that belong to it, now, and the company's rules.
 *
 * - First punch = in, last punch = out (one punch: in only).
 * - Worked = out - in, less the break when more than the break was worked.
 * - Late = minutes after the shift start; beyond the grace minutes it is
 *   late, beyond the half-day minutes a half day.
 * - Over time = worked beyond the shift's expected minutes, counted from the
 *   rule's minimum on; on a day off everything worked is over time.
 * - No punch: absent once the shift is over, pending before.
 */
final class DayCalculator
{
    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, break: int, expected: int}|null  $shift
     * @param  list<CarbonImmutable>  $punches  Sorted, not voided.
     * @param  array{grace: int, half_day: int, overtime_min: int}  $rules
     * @return array{status: DayStatus, first_in_at: CarbonImmutable|null, last_out_at: CarbonImmutable|null, worked_minutes: int, late_minutes: int, overtime_minutes: int}
     */
    public static function day(?array $shift, ?DayStatus $off, array $punches, CarbonImmutable $now, array $rules): array
    {
        $in = $punches[0] ?? null;
        $out = count($punches) > 1 ? $punches[count($punches) - 1] : null;
        $span = $in !== null && $out !== null ? (int) $in->diffInMinutes($out) : 0;
        $break = $shift['break'] ?? 0;
        $worked = $span > $break ? $span - $break : $span;
        $result = ['first_in_at' => $in, 'last_out_at' => $out, 'worked_minutes' => min($worked, 65535), 'late_minutes' => 0, 'overtime_minutes' => 0];

        if ($off !== null) {
            return [...$result, 'status' => $off, 'overtime_minutes' => self::overtime($worked, $rules['overtime_min'])];
        }
        if ($shift === null) {
            return [...$result, 'status' => $in === null ? DayStatus::NoShift : DayStatus::Present];
        }

        $over = $now->greaterThanOrEqualTo($shift['end']);
        if ($in === null) {
            return [...$result, 'status' => $over ? DayStatus::Absent : DayStatus::Pending];
        }

        $late = $in->greaterThan($shift['start']) ? (int) $shift['start']->diffInMinutes($in) : 0;
        $status = match (true) {
            $out === null && $over => DayStatus::Incomplete,
            $rules['half_day'] > 0 && $late > $rules['half_day'] => DayStatus::HalfDay,
            $late > $rules['grace'] => DayStatus::Late,
            default => DayStatus::Present,
        };

        return [
            ...$result,
            'status' => $status,
            'late_minutes' => min($late, 65535),
            'overtime_minutes' => self::overtime($worked - $shift['expected'], $rules['overtime_min']),
        ];
    }

    private static function overtime(int $extra, int $minimum): int
    {
        return $extra > 0 && $extra >= $minimum ? min($extra, 65535) : 0;
    }
}
