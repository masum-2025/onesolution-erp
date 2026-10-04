<?php

namespace Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Attendance\Exceptions\AttendanceException;
use Modules\Attendance\Http\AttendancePresenter;
use Modules\Attendance\Http\Controllers\Concerns\FindsWorkplace;
use Modules\Attendance\Http\Requests\HolidayRequest;
use Modules\Attendance\Http\Requests\RosterRequest;
use Modules\Attendance\Http\Requests\ShiftRequest;
use Modules\Attendance\Models\Holiday;
use Modules\Attendance\Models\Roster;
use Modules\Attendance\Models\Shift;
use Modules\Attendance\Services\Schedules;
use Modules\Attendance\Services\Workplace;

/**
 * Working hours: shifts and holidays of the company (read with
 * attendance.view, changed with attendance.manage at the company; a unit's
 * own holiday at that unit), and rosters (at each employee's unit).
 */
class ScheduleController extends Controller
{
    use FindsWorkplace;

    public function __construct(private Workplace $workplace, private Schedules $schedules, private AttendancePresenter $presenter) {}

    public function shifts(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('attendance.view', $unit);

        return response()->json(['data' => $this->workplace->query(Shift::class, $company)->orderBy('start_minute')->orderBy('code')->get()
            ->map(fn (Shift $shift) => $this->presenter->shift($shift))->values()]);
    }

    public function storeShift(ShiftRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('attendance.manage', $company);

        return response()->json(['data' => $this->presenter->shift($this->schedules->createShift($company, $request->validated(), $request->user()))], 201);
    }

    public function updateShift(ShiftRequest $request, string $organization, string $shift): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Shift::class, $unit, $company, $shift, AttendanceException::shiftNotFound());
        Gate::authorize('attendance.manage', $company);
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->shift($this->schedules->updateShift($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }

    public function holidays(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('attendance.view', $unit);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? 0);
        $year = $year ?: $this->workplace->today($company)->year;

        // The company's days off, and those of this unit and the units above it.
        $units = [...$unit->ancestorIds(), $unit->getKey(), ...$this->workplace->subtreeIds($unit)];

        return response()->json(['data' => $this->workplace->query(Holiday::class, $company)
            ->whereBetween('on', ["{$year}-01-01", "{$year}-12-31"])
            ->where(fn ($query) => $query->whereNull('unit_id')->orWhereIn('unit_id', $units))
            ->orderBy('on')->get()->map(fn (Holiday $holiday) => $this->presenter->holiday($holiday))->values()]);
    }

    public function storeHoliday(HolidayRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $for = $request->validated('unit_id');
        if ($for !== null && ($for === $company->getKey() || ! $this->within($company, $for) || ! $this->within($unit, $for))) {
            throw AttendanceException::notCompanyUnit();
        }
        Gate::authorize('attendance.manage', $for === null ? $company : $this->unitOf($for));

        $holiday = $this->schedules->addHoliday($company, [...$request->validated(), 'unit_id' => $for], $request->user());

        return response()->json(['data' => $this->presenter->holiday($holiday)], 201);
    }

    public function destroyHoliday(Request $request, string $organization, string $holiday): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Holiday::class, $unit, $company, $holiday, AttendanceException::holidayNotFound());
        Gate::authorize('attendance.manage', $found->unit_id === null ? $company : $this->unitOf($found->unit_id));

        $this->schedules->removeHoliday($company, $found, $request->user());

        return response()->json(null, 204);
    }

    /** One employee's rosters, newest first. */
    public function rosters(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $employee = $this->employeeIn($unit, $company, (string) $request->validate(['employee_id' => ['required', 'string', 'max:26']])['employee_id']);
        Gate::authorize('attendance.view', $this->unitOf($employee->unitId));

        $rosters = $this->workplace->query(Roster::class, $company)->where('employee_id', $employee->id)->orderByDesc('from')->limit(50)->get();
        $shifts = $this->workplace->query(Shift::class, $company)->whereKey($rosters->pluck('shift_id')->filter()->unique()->values()->all())->get()->keyBy('id');

        return response()->json(['data' => $rosters->map(fn (Roster $roster) => $this->presenter->roster($roster, $shifts[$roster->shift_id] ?? null))->values()]);
    }

    public function assign(RosterRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $employees = array_map(fn (string $id) => $this->employeeIn($unit, $company, $id), $request->validated('employee_ids'));
        foreach (array_unique(array_map(fn ($employee) => $employee->unitId, $employees)) as $unitId) {
            Gate::authorize('attendance.manage', $this->unitOf($unitId));
        }
        $shiftId = $request->validated('shift_id');
        $shift = $shiftId === null ? null : $this->recordIn(Shift::class, $unit, $company, $shiftId, AttendanceException::shiftNotFound());

        $count = $this->schedules->assign($company, $employees, $shift, CarbonImmutable::parse($request->validated('from'), 'UTC'), $request->user());

        return response()->json(['data' => ['assigned' => $count]], 201);
    }
}
