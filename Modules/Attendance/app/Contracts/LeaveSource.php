<?php

namespace Modules\Attendance\Contracts;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Approved leave, from a module that keeps it (Leave), tagged
 * "attendance.leave_sources". Attendance never reads that module's tables:
 * a day without punches on leave shows as leave, not absent, and leave
 * without pay reaches Payroll through the attendance summary.
 */
interface LeaveSource
{
    /**
     * The employee's approved leave on a day: paid or not, a whole day or
     * half of it; null when not on leave.
     *
     * @return array{paid: bool, half: bool}|null
     */
    public function leaveOn(Organization $company, string $employeeId, CarbonImmutable $day): ?array;
}
