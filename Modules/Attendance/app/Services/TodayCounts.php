<?php

namespace Modules\Attendance\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\Attendance\Enums\DayStatus;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Attendance's public service for other modules (a school's public
 * website): how many staff were present, late, absent or on leave today at
 * the company. Numbers only, never names. People whose day is not over and
 * who have not come in yet, and days off, are not counted.
 */
class TodayCounts
{
    public function __construct(private Workplace $workplace, private Days $days, private EmployeeDirectory $directory) {}

    /**
     * @return array{present: int, late: int, absent: int, leave: int, taken: int, date: string}
     */
    public function forCompany(Organization $company): array
    {
        $today = $this->workplace->today($company);
        $employees = $this->directory->inUnits($company, $this->workplace->subtreeIds($company), $today);
        $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0];
        foreach ($this->days->computeRange($company, $employees, $today, $today) as $day) {
            $key = match ($day->status) {
                DayStatus::Present, DayStatus::Incomplete => 'present',
                DayStatus::Late, DayStatus::HalfDay => 'late',
                DayStatus::Absent => 'absent',
                DayStatus::Leave, DayStatus::UnpaidLeave => 'leave',
                default => null,
            };
            if ($key !== null) {
                $counts[$key]++;
            }
        }

        return [...$counts, 'taken' => array_sum($counts), 'date' => $today->toDateString()];
    }
}
