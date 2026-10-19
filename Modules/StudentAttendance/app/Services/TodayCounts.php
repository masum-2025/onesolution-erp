<?php

namespace Modules\StudentAttendance\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\StudentAttendance\Models\Mark;

/**
 * StudentAttendance's public service for other modules (a school's public
 * website): how many students were present, late, absent or on leave today
 * at the company, from the days worked out like everywhere else (DayLedger).
 * Numbers only, never names. Students whose attendance was not taken today
 * are not counted.
 */
class TodayCounts
{
    public function __construct(private Register $register, private DayLedger $ledger) {}

    /**
     * @return array{present: int, late: int, absent: int, leave: int, taken: int, date: string}
     */
    public function forCompany(Organization $company): array
    {
        $today = $this->register->today($company);
        $students = $this->register->query(Mark::class, $company)->whereDate('taken_on', $today->toDateString())
            ->distinct()->pluck('student_id')->all();
        $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0];
        foreach ($this->ledger->days($company, $students, $today, $today) as $days) {
            $status = $days[$today->toDateString()]['status'] ?? null;
            if ($status !== null && array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return [...$counts, 'taken' => array_sum($counts), 'date' => $today->toDateString()];
    }
}
