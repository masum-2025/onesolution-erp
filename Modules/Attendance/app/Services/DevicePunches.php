<?php

namespace Modules\Attendance\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Modules\Attendance\Contracts\DeviceAttendance;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Attendance's side of DeviceAttendance: punches a terminal module brings
 * become device punches with the same op id a file import would give them,
 * so a punch seen both ways counts once; days are worked out after all.
 */
class DevicePunches implements DeviceAttendance
{
    public function __construct(
        private Punches $punches,
        private Days $days,
        private Workplace $workplace,
        private EmployeeDirectory $directory,
    ) {}

    public function record(Organization $company, array $punches, ?User $actor): array
    {
        $employees = $this->directory->many($company, array_values(array_unique(array_column($punches, 'employee_id'))));
        $results = [];
        $touched = [];
        foreach ($punches as $punch) {
            $employee = $employees[$punch['employee_id']] ?? null;
            if ($employee === null) {
                $results[] = 'unknown';

                continue;
            }
            $instant = $punch['at']->utc();
            $day = $this->workplace->dayOf($company, $instant);
            if (! $employee->employedOn($day)) {
                $results[] = 'not_employed';

                continue;
            }
            // The same op id as DeviceImports: a punch also brought in from the machine's file counts once.
            $opId = 'device-'.substr(hash('sha256', $employee->id.'|'.$instant->toIso8601String()), 0, 40);
            $kept = $this->punches->fromDevice($company, $employee, $instant, $opId, $actor);
            $results[] = $kept->wasRecentlyCreated ? 'added' : 'already';
            $touched[$employee->id.'|'.$day->toDateString()] = [$employee, $instant];
        }
        foreach ($touched as [$employee, $instant]) {
            $this->days->touch($company, $employee, $instant);
        }

        return $results;
    }
}
