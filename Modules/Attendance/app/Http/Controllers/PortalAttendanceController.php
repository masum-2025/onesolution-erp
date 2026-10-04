<?php

namespace Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Tenancy\Context\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Attendance\Http\AttendancePresenter;
use Modules\Attendance\Services\Days;
use Modules\Attendance\Services\Workplace;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * A portal member's own attendance (B2B2C: an employee who uses the client's
 * portal): the days of a month of the employee records the client linked
 * to them (portal subject hrm.employee). Nothing else; staff use the staff
 * screens.
 */
class PortalAttendanceController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private Workplace $workplace,
        private Days $days,
        private EmployeeDirectory $directory,
        private AttendancePresenter $presenter,
    ) {}

    public function days(Request $request): JsonResponse
    {
        if (! $this->portal->isPortal()) {
            return response()->json(['data' => []]);
        }
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;
        $company = $this->workplace->companyOf($this->context->organization());
        $employees = array_values($this->directory->many($company, $this->portal->subjectIds('hrm.employee')));

        $today = $this->workplace->today($company);
        $from = $month === null ? $today->startOfMonth() : CarbonImmutable::parse("{$month}-01", 'UTC');
        $to = $from->endOfMonth()->startOfDay()->min($today);
        $days = $employees === [] || $to->lessThan($from) ? collect() : $this->days->computeRange($company, $employees, $from, $to);
        $byId = collect($employees)->keyBy('id');

        return response()->json(['data' => $days->sortByDesc(fn ($day) => $day->work_date)
            ->map(fn ($day) => $this->presenter->day($day, $byId[$day->employee_id] ?? null))->values()]);
    }
}
