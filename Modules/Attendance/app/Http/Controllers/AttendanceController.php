<?php

namespace Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Attendance\Exceptions\AttendanceException;
use Modules\Attendance\Http\AttendancePresenter;
use Modules\Attendance\Http\Controllers\Concerns\FindsWorkplace;
use Modules\Attendance\Http\Requests\CorrectionRequest;
use Modules\Attendance\Http\Requests\PunchRequest;
use Modules\Attendance\Models\Correction;
use Modules\Attendance\Models\Punch;
use Modules\Attendance\Services\Corrections;
use Modules\Attendance\Services\Days;
use Modules\Attendance\Services\Punches;
use Modules\Attendance\Services\Workplace;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * The team's attendance at a unit (and below it): days of a period
 * (attendance.view), punches written or voided by HR (attendance.manage),
 * and corrections asked for someone (attendance.manage) and decided by
 * someone else (attendance.correct).
 */
class AttendanceController extends Controller
{
    use FindsWorkplace;

    /** Most employees on one page of days. */
    private const PAGE = 50;

    public function __construct(
        private Workplace $workplace,
        private Days $days,
        private Punches $punches,
        private Corrections $corrections,
        private EmployeeDirectory $directory,
        private AttendancePresenter $presenter,
    ) {}

    /** Days of the employees at the unit between two dates (one employee with employee_id). */
    public function days(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('attendance.view', $unit);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'string', 'max:26'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $from = CarbonImmutable::parse($data['from'], 'UTC');
        $to = CarbonImmutable::parse($data['to'], 'UTC');

        $employees = isset($data['employee_id'])
            ? [$this->employeeIn($unit, $company, $data['employee_id'])]
            : $this->directory->inUnits($company, $this->workplace->subtreeIds($unit));
        $page = (int) ($data['page'] ?? 1);
        $slice = array_slice($employees, ($page - 1) * self::PAGE, self::PAGE);
        $byId = collect($slice)->keyBy('id');

        $days = $this->days->computeRange($company, $slice, $from, $to);

        return response()->json([
            'data' => $days->map(fn ($day) => $this->presenter->day($day, $byId[$day->employee_id] ?? null))->values(),
            'meta' => ['page' => $page, 'per_page' => self::PAGE, 'employees' => count($employees)],
        ]);
    }

    /** An employee's punches between two days (voided ones too, marked). */
    public function punches(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $data = $request->validate(['employee_id' => ['required', 'string', 'max:26'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $employee = $this->employeeIn($unit, $company, $data['employee_id']);
        Gate::authorize('attendance.view', $this->unitOf($employee->unitId));

        return response()->json(['data' => $this->workplace->query(Punch::class, $company)->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $this->workplace->at($company, CarbonImmutable::parse($data['from'], 'UTC'), 0))
            ->where('punched_at', '<', $this->workplace->at($company, CarbonImmutable::parse($data['to'], 'UTC')->addDay(), 0))
            ->orderBy('punched_at')->limit(500)->get()->map(fn (Punch $punch) => $this->presenter->punch($punch))->values()]);
    }

    public function writePunch(PunchRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $employee = $this->employeeIn($unit, $company, $request->validated('employee_id'));
        Gate::authorize('attendance.manage', $this->unitOf($employee->unitId));
        $at = $this->workplace->localInstant($company, $request->validated('at'));
        if ($at->greaterThan(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['at' => __('attendance::attendance.validation.future_punch')]);
        }

        $punch = $this->punches->manual($company, $employee, $at, $request->validated('note'), $request->validated('op_id'), $request->user());

        return response()->json(['data' => $this->presenter->punch($punch)], 201);
    }

    public function voidPunch(PunchRequest $request, string $organization, string $punch): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Punch::class, $unit, $company, $punch, AttendanceException::punchNotFound());
        Gate::authorize('attendance.manage', $this->unitOf($found->unit_id));

        return response()->json(['data' => $this->presenter->punch($this->punches->void($company, $found, $request->validated('reason'), $request->user()))]);
    }

    /** Corrections of the unit's people (pending first). */
    public function corrections(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('attendance.view', $unit);
        $status = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']])['status'] ?? null;

        $list = $this->workplace->query(Correction::class, $company)->whereIn('unit_id', $this->workplace->subtreeIds($unit))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')->limit(100)->get()
            ->sortBy(fn (Correction $correction) => $correction->status === Correction::PENDING ? 0 : 1)->values();
        $employees = $this->directory->many($company, $list->pluck('employee_id')->unique()->values()->all());

        return response()->json(['data' => $list->map(fn (Correction $correction) => $this->presenter->correction(
            $correction,
            $employees[$correction->employee_id] ?? null,
            $correction->status === Correction::PENDING && $correction->requested_by !== $request->user()->getKey()
                && ($employees[$correction->employee_id] ?? null)?->userId !== $request->user()->getKey()
                && Gate::allows('attendance.correct', $this->unitOf($correction->unit_id)),
        ))->values()]);
    }

    /** HR asks on someone's behalf (a person without a login, or who cannot). */
    public function ask(CorrectionRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $employee = $this->employeeIn($unit, $company, (string) $request->validated('employee_id'));
        Gate::authorize('attendance.manage', $this->unitOf($employee->unitId));

        $correction = $this->corrections->ask($company, $employee, $request->safe()->except('employee_id'), $request->user());

        return response()->json(['data' => $this->presenter->correction($correction, $employee)], 201);
    }

    public function decide(CorrectionRequest $request, string $organization, string $correction, string $step): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Correction::class, $unit, $company, $correction, AttendanceException::correctionNotFound());
        Gate::authorize('attendance.correct', $this->unitOf($found->unit_id));
        $version = (int) $request->validated('base_version');

        $decided = match ($step) {
            'approve' => $this->corrections->approve($company, $found, $version, $request->user()),
            'reject' => $this->corrections->reject($company, $found, $version, $request->validated('note'), $request->user()),
            default => throw AttendanceException::unknownStep(),
        };

        return response()->json(['data' => $this->presenter->correction($decided)]);
    }
}
