<?php

namespace Modules\Leave\Attendance;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Attendance\Contracts\LeaveSource;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Models\LeaveType;
use Modules\Leave\Services\LeavePolicy;
use Modules\Leave\Services\Place;
use Modules\Leave\Services\WorkCalendar;

/**
 * Approved leave for Attendance (its LeaveSource contract, tagged
 * "attendance.leave_sources"): whether a day is covered, paid or not, and
 * whether only half of it. A request's paid half days come first, the part
 * without pay after them. Nothing while Leave is off for the company.
 */
class LeaveDays implements LeaveSource
{
    public function __construct(private ModuleResolver $modules, private Place $place, private WorkCalendar $calendar) {}

    public function leaveOn(Organization $company, string $employeeId, CarbonImmutable $day): ?array
    {
        if (! $this->modules->isEnabled('leave', $company)) {
            return null;
        }
        $date = $day->toDateString();
        $request = $this->place->query(LeaveRequest::class, $company)->where('employee_id', $employeeId)->where('status', LeaveRequest::APPROVED)
            ->where('from_date', '<=', $date)->where('to_date', '>=', $date)->first();
        if ($request === null) {
            return null;
        }
        $type = $this->place->query(LeaveType::class, $company)->whereKey($request->type_id)->first();
        $unit = Organization::query()->find($request->unit_id);
        if ($type === null || $unit === null) {
            return null;
        }

        $days = LeavePolicy::days($request->from_date, $request->to_date, $request->from_half, $request->to_half, $this->calendar->offDays($company, $unit), $type->counts_days_off);

        return LeavePolicy::payByDay($days, $request->halves - $request->unpaid_halves)[$date] ?? null;
    }
}
