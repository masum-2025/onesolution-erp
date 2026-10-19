<?php

namespace Modules\Attendance\Services;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Attendance\Enums\DayStatus;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Attendance's public service for other modules (Payroll): per employee,
 * how many days of each kind and how many minutes worked, late, left early
 * and over time in a period, and on how many days someone left early. Days
 * are worked out again first, so the figures follow the latest punches,
 * corrections and rosters. Never punch times or places.
 */
class AttendanceSummary
{
    public function __construct(private Days $days, private EmployeeDirectory $directory) {}

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array{days: array<string, int>, attended_days: int, worked_minutes: int, late_minutes: int, early_minutes: int, early_days: int, overtime_minutes: int}>
     */
    public function forPeriod(Organization $company, array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $employees = array_values($this->directory->many($company, $employeeIds));
        $summary = [];
        foreach ($employees as $employee) {
            $summary[$employee->id] = [
                'days' => array_fill_keys(array_map(fn (DayStatus $status) => $status->value, DayStatus::cases()), 0),
                'attended_days' => 0, 'worked_minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'early_days' => 0, 'overtime_minutes' => 0,
            ];
        }

        foreach ($this->days->computeRange($company, $employees, $from, $to) as $day) {
            $row = &$summary[$day->employee_id];
            $row['days'][$day->status->value]++;
            $row['attended_days'] += $day->status->attended() ? 1 : 0;
            $row['worked_minutes'] += $day->worked_minutes;
            $row['late_minutes'] += $day->late_minutes;
            $row['early_minutes'] += $day->early_minutes;
            $row['early_days'] += $day->early_minutes > 0 ? 1 : 0;
            $row['overtime_minutes'] += $day->overtime_minutes;
            unset($row);
        }

        return $summary;
    }
}
