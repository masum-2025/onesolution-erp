<?php

namespace Modules\StudentAttendance\Exams;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Contracts\StudentAttendance;
use Modules\StudentAttendance\Services\DayLedger;

/**
 * Days attended for report cards (Exams' StudentAttendance contract): each
 * student's present days and school days in the session. Nothing while
 * this module is off for the institution, so the line disappears.
 */
class ReportCardAttendance implements StudentAttendance
{
    public function __construct(private DayLedger $ledger, private AcademicDirectory $academic, private ModuleResolver $modules) {}

    public function forSession(Organization $company, string $sessionId, array $studentIds): array
    {
        $session = $this->academic->session($company, $sessionId);
        if ($session === null || ! $this->modules->isEnabled('student_attendance', $company)) {
            return [];
        }
        $days = $this->ledger->days($company, $studentIds, CarbonImmutable::parse($session['starts_on']), CarbonImmutable::parse($session['ends_on']), $sessionId);

        return collect($days)->map(function (array $studentDays) use ($company) {
            $totals = $this->ledger->totals($company, $studentDays);

            return ['present' => $totals['present'], 'days' => $totals['days']];
        })->all();
    }
}
