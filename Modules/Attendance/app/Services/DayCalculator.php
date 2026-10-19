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
 * - Left early = minutes the last punch is before the shift's end, kept only
 *   beyond the early-leave grace (0 within it, or without a last punch).
 * - Over time = worked beyond the shift's expected minutes, counted from the
 *   rule's minimum on; on a day off everything worked is over time.
 * - No punch: absent once the shift is over, pending before.
 * - Approved leave (from a LeaveSource): a whole day without punches is
 *   leave (paid or not); half a day ignores lateness, and without any
 *   punch the other half is a half day missed.
 */
final class DayCalculator
{
    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, break: int, expected: int}|null  $shift
     * @param  list<CarbonImmutable>  $punches  Sorted, not voided.
     * @param  array{grace: int, half_day: int, overtime_min: int, early_grace?: int}  $rules
     * @param  array{paid: bool, half: bool}|null  $leave  Approved leave that day (Leave module), if any.
     * @return array{status: DayStatus, leave: string|null, first_in_at: CarbonImmutable|null, last_out_at: CarbonImmutable|null, worked_minutes: int, late_minutes: int, early_minutes: int, overtime_minutes: int}
     */
    public static function day(?array $shift, ?DayStatus $off, array $punches, CarbonImmutable $now, array $rules, ?array $leave = null): array
    {
        $in = $punches[0] ?? null;
        $out = count($punches) > 1 ? $punches[count($punches) - 1] : null;
        $span = $in !== null && $out !== null ? (int) $in->diffInMinutes($out) : 0;
        $break = $shift['break'] ?? 0;
        $worked = $span > $break ? $span - $break : $span;
        $result = ['leave' => null, 'first_in_at' => $in, 'last_out_at' => $out, 'worked_minutes' => min($worked, 65535), 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0];

        if ($off !== null) {
            return [...$result, 'status' => $off, 'overtime_minutes' => self::overtime($worked, $rules['overtime_min'])];
        }
        // Leave: a whole day away shows as leave; came to work anyway, it is a normal day.
        // Half a day: lateness and leaving early do not count; nobody came, the other half is missed.
        $leaveCode = $leave === null ? null : ($leave['paid'] ? 'paid' : 'unpaid').($leave['half'] ? '_half' : '');
        $onLeave = $leave !== null && ! $leave['half'] ? ($leave['paid'] ? DayStatus::Leave : DayStatus::UnpaidLeave) : null;
        if ($onLeave !== null && $in === null) {
            return [...$result, 'status' => $onLeave, 'leave' => $leaveCode];
        }
        if ($onLeave !== null) {
            $leaveCode = null;
        }
        if ($shift === null) {
            return [...$result, 'status' => $in === null ? DayStatus::NoShift : DayStatus::Present, 'leave' => $leaveCode];
        }

        $over = $now->greaterThanOrEqualTo($shift['end']);
        if ($in === null) {
            $status = $over ? ($leaveCode !== null ? DayStatus::HalfDay : DayStatus::Absent) : DayStatus::Pending;

            return [...$result, 'status' => $status, 'leave' => $leaveCode];
        }
        if ($leaveCode !== null) {
            return [
                ...$result,
                'status' => $out === null && $over ? DayStatus::Incomplete : DayStatus::Present,
                'leave' => $leaveCode,
                'overtime_minutes' => self::overtime($worked - intdiv($shift['expected'], 2), $rules['overtime_min']),
            ];
        }

        $late = $in->greaterThan($shift['start']) ? (int) $shift['start']->diffInMinutes($in) : 0;
        $status = match (true) {
            $out === null && $over => DayStatus::Incomplete,
            $rules['half_day'] > 0 && $late > $rules['half_day'] => DayStatus::HalfDay,
            $late > $rules['grace'] => DayStatus::Late,
            default => DayStatus::Present,
        };

        $early = $out !== null && $out->lessThan($shift['end']) ? (int) $out->diffInMinutes($shift['end']) : 0;

        return [
            ...$result,
            'status' => $status,
            'late_minutes' => min($late, 65535),
            'early_minutes' => $early > ($rules['early_grace'] ?? 0) ? min($early, 65535) : 0,
            'overtime_minutes' => self::overtime($worked - $shift['expected'], $rules['overtime_min']),
        ];
    }

    private static function overtime(int $extra, int $minimum): int
    {
        return $extra > 0 && $extra >= $minimum ? min($extra, 65535) : 0;
    }
}
