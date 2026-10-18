<?php

namespace Modules\StudentAttendance\Services;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\StudentAttendance\Models\Mark;
use Modules\StudentAttendance\Models\Sheet;

/**
 * Each student's days from their sheets: a school day is a day their
 * attendance was taken (so weekly days off and holidays never count).
 *
 * - A whole-day sheet gives the day's state.
 * - By period: leave in every period is leave; otherwise present when the
 *   student was present (or late) in at least the rule's share of the
 *   periods taken (student_attendance.period_present_share_bp), else absent.
 *   A day with period sheets ignores a whole-day sheet of the same day.
 * - Present days count late days too when the rule
 *   student_attendance.late_counts_present says so (rules read at the campus).
 */
class DayLedger
{
    public function __construct(private Register $register) {}

    /**
     * Days of students between two dates (and of one session only, when given).
     *
     * @param  list<string>  $studentIds
     * @return array<string, array<string, array{status: string, unit_id: string, periods: list<array{period_id: string, subject_id: ?string, status: string}>}>> By student, then date.
     */
    public function days(Organization $company, array $studentIds, CarbonImmutable $from, CarbonImmutable $to, ?string $sessionId = null): array
    {
        if ($studentIds === []) {
            return [];
        }
        $marks = $this->register->query(Mark::class, $company)->whereIn('student_id', $studentIds)
            ->whereDate('taken_on', '>=', $from->toDateString())->whereDate('taken_on', '<=', $to->toDateString())
            ->get(['sheet_id', 'student_id', 'taken_on', 'status']);
        $sheets = $this->register->query(Sheet::class, $company)->whereIn('id', $marks->pluck('sheet_id')->unique()->values()->all())
            ->when($sessionId !== null, fn ($query) => $query->where('session_id', $sessionId))
            ->get(['id', 'unit_id', 'slot', 'period_id', 'subject_id'])->keyBy('id');

        $grouped = [];
        foreach ($marks as $mark) {
            $sheet = $sheets[$mark->sheet_id] ?? null;
            if ($sheet !== null) {
                $grouped[$mark->student_id][$mark->taken_on->toDateString()][] = [$sheet, $mark->status];
            }
        }

        // Each campus's share, read once for this call.
        $shares = [];
        $days = [];
        foreach ($grouped as $studentId => $dates) {
            ksort($dates);
            foreach ($dates as $date => $entries) {
                $periods = array_values(array_filter($entries, fn (array $entry) => $entry[0]->slot !== Sheet::DAY));
                $unitId = $entries[0][0]->unit_id;
                if ($periods === []) {
                    $status = $entries[0][1];
                } else {
                    $counted = array_values(array_filter($periods, fn (array $entry) => $entry[1] !== 'leave'));
                    $present = count(array_filter($counted, fn (array $entry) => in_array($entry[1], ['present', 'late'], true)));
                    $share = $shares[$unitId] ??= (int) $this->register->rule('student_attendance.period_present_share_bp', $company, $unitId);
                    $status = $counted === [] ? 'leave' : ($present * 10000 >= $share * count($counted) ? 'present' : 'absent');
                }
                $days[$studentId][$date] = [
                    'status' => $status,
                    'unit_id' => $unitId,
                    'periods' => array_map(fn (array $entry) => ['period_id' => $entry[0]->period_id, 'subject_id' => $entry[0]->subject_id, 'status' => $entry[1]], $periods),
                ];
            }
        }

        return $days;
    }

    /**
     * Totals of a student's days: present (late too when the rule counts it), absent, late, leave, and school days.
     *
     * @param  array<string, array{status: string, unit_id: string}>  $days
     * @return array{present: int, absent: int, late: int, leave: int, days: int}
     */
    public function totals(Organization $company, array $days): array
    {
        $totals = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'days' => count($days)];
        $counts = [];
        foreach ($days as $day) {
            $totals[$day['status']]++;
            if ($day['status'] === 'late' && ($counts[$day['unit_id']] ??= (bool) $this->register->rule('student_attendance.late_counts_present', $company, $day['unit_id']))) {
                $totals['present']++;
            }
        }

        return $totals;
    }
}
