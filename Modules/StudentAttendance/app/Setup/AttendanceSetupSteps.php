<?php

namespace Modules\StudentAttendance\Setup;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Number;
use Modules\Education\Contracts\SetupStepProvider;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Models\Holiday;
use Modules\StudentAttendance\Models\Period;
use Modules\StudentAttendance\Services\Register;

/**
 * Attendance on the setup checklist: each campus with classes has its
 * periods (only where attendance is taken in each period), then this
 * year's holidays (a help, not a need). Class teachers are Education's step.
 */
class AttendanceSetupSteps implements SetupStepProvider
{
    public function __construct(private Register $register, private AcademicDirectory $academic) {}

    public function module(): string
    {
        return 'student_attendance';
    }

    public function steps(Organization $company, ?string $sessionId, array $unitIds): array
    {
        $text = fn (string $key, array $replace = []) => __("student_attendance::setup.steps.{$key}", $replace);
        $steps = [];
        $campuses = $sessionId === null ? [] : collect($this->academic->sessionSections($company, $sessionId, $unitIds))->pluck('unit_id')->unique()
            ->filter(fn (string $unitId) => (bool) $this->register->rule('student_attendance.by_period', $company, $unitId))->values()->all();
        if ($campuses !== []) {
            $withPeriods = $this->register->query(Period::class, $company)->whereIn('unit_id', $campuses)->where('is_active', true)->distinct()->pluck('unit_id')->all();
            $missing = count(array_diff($campuses, $withPeriods));
            $steps[] = ['key' => 'periods', 'label' => $text('periods.label'), 'route' => '/student-attendance/periods', 'done' => $missing === 0,
                'detail' => $missing === 0 ? null : $text('periods.missing', ['count' => Number::format($missing, locale: app()->getLocale())])];
        }
        $year = $this->register->today($company)->year;
        $holidays = $this->register->query(Holiday::class, $company)->where(fn ($query) => $query->whereNull('unit_id')->orWhereIn('unit_id', $unitIds))
            ->whereDate('ends_on', '>=', "{$year}-01-01")->whereDate('starts_on', '<=', "{$year}-12-31")->exists();
        $steps[] = ['key' => 'holidays', 'label' => $text('holidays.label'), 'route' => '/student-attendance/holidays', 'optional' => true,
            'done' => $holidays, 'detail' => $holidays ? null : $text('holidays.missing')];

        return $steps;
    }
}
