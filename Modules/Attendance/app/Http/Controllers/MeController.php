<?php

namespace Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Attendance\Http\AttendancePresenter;
use Modules\Attendance\Http\Controllers\Concerns\FindsWorkplace;
use Modules\Attendance\Http\Requests\CorrectionRequest;
use Modules\Attendance\Models\Correction;
use Modules\Attendance\Models\Punch;
use Modules\Attendance\Services\Corrections;
use Modules\Attendance\Services\Days;
use Modules\Attendance\Services\Punches;
use Modules\Attendance\Services\Workplace;

/**
 * An employee's own attendance, through the login HR linked to them: today,
 * checking in and out (attendance.punch), their days of a month and asking
 * to fix one. Only their own records, ever.
 */
class MeController extends Controller
{
    use FindsWorkplace;

    public function __construct(
        private Workplace $workplace,
        private Punches $punches,
        private Days $days,
        private Corrections $corrections,
        private AttendancePresenter $presenter,
    ) {}

    /** Who they are here, today's day and punches, and whether they may punch. */
    public function show(Request $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->punches->employeeOf($company, $request->user());
        $today = $this->workplace->today($company);
        $day = $this->days->compute($company, $employee, $today);

        return response()->json(['data' => [
            'employee' => $this->presenter->employee($employee),
            'today' => $day === null ? null : $this->presenter->day($day),
            'punches' => $this->workplace->query(Punch::class, $company)->where('employee_id', $employee->id)->whereNull('voided_at')
                ->where('punched_at', '>=', $this->workplace->at($company, $today->subDay(), 0))
                ->orderByDesc('punched_at')->limit(20)->get()->map(fn (Punch $punch) => $this->presenter->punch($punch))->values(),
            'timezone' => $this->workplace->timezone($company),
            'can_punch' => Gate::allows('attendance.punch', $this->unitOf($employee->unitId)),
        ]]);
    }

    public function punch(Request $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->punches->employeeOf($company, $request->user());
        Gate::authorize('attendance.punch', $this->unitOf($employee->unitId));
        $opId = $request->validate(['op_id' => ['nullable', 'string', 'max:64']])['op_id'] ?? null;

        $punch = $this->punches->self($company, $request->user(), $opId);
        $day = $this->days->compute($company, $employee, $this->workplace->dayOf($company, $punch->punched_at));

        return response()->json(['data' => ['punch' => $this->presenter->punch($punch), 'day' => $day === null ? null : $this->presenter->day($day)]], 201);
    }

    /** Their days of a month (default: this month, up to today). */
    public function days(Request $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->punches->employeeOf($company, $request->user());
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;
        $today = $this->workplace->today($company);
        $from = $month === null ? $today->startOfMonth() : CarbonImmutable::parse("{$month}-01", 'UTC');
        $to = $from->endOfMonth()->startOfDay()->min($today);

        $days = $to->lessThan($from) ? collect() : $this->days->computeRange($company, [$employee], $from, $to);

        return response()->json(['data' => $days->sortByDesc(fn ($day) => $day->work_date)->map(fn ($day) => $this->presenter->day($day))->values()]);
    }

    public function corrections(Request $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->punches->employeeOf($company, $request->user());

        return response()->json(['data' => $this->workplace->query(Correction::class, $company)->where('employee_id', $employee->id)
            ->orderByDesc('work_date')->limit(50)->get()->map(fn (Correction $correction) => $this->presenter->correction($correction))->values()]);
    }

    public function ask(CorrectionRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->punches->employeeOf($company, $request->user());
        Gate::authorize('attendance.punch', $this->unitOf($employee->unitId));

        $correction = $this->corrections->ask($company, $employee, $request->safe()->except('employee_id'), $request->user());

        return response()->json(['data' => $this->presenter->correction($correction)], 201);
    }
}
