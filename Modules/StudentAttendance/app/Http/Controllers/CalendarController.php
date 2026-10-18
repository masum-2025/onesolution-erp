<?php

namespace Modules\StudentAttendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Exceptions\StudentAttendanceException;
use Modules\StudentAttendance\Http\AttendancePresenter;
use Modules\StudentAttendance\Http\Controllers\Concerns\AttendancePlace;
use Modules\StudentAttendance\Http\Requests\HolidayRequest;
use Modules\StudentAttendance\Http\Requests\PeriodRequest;
use Modules\StudentAttendance\Models\Holiday;
use Modules\StudentAttendance\Models\Period;
use Modules\StudentAttendance\Services\Calendar;
use Modules\StudentAttendance\Services\Register;

/**
 * A campus's periods and the institution's holidays. Reading needs
 * student_attendance.view; changing needs .manage at the unit. A holiday
 * for the whole institution is added and removed from the institution.
 */
class CalendarController extends Controller
{
    use AttendancePlace;

    public function __construct(
        private Register $register,
        private Calendar $calendar,
        private AcademicDirectory $academic,
        private AttendancePresenter $presenter,
    ) {}

    public function periods(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.view', $unit);

        return response()->json(['data' => $this->calendar->periods($company, $this->register->subtreeIds($unit))
            ->map(fn (Period $period) => $this->presenter->period($period))->values()]);
    }

    public function storePeriod(PeriodRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.manage', $unit);
        $data = $request->validated();
        $data['unit_id'] ??= $unit->getKey();
        if (! in_array($data['unit_id'], $this->register->subtreeIds($unit), true)) {
            throw ValidationException::withMessages(['unit_id' => __('student_attendance::attendance.validation.unit')]);
        }

        return response()->json(['data' => $this->presenter->period($this->calendar->savePeriod($company, null, $data, $request->user()))], 201);
    }

    public function updatePeriod(PeriodRequest $request, string $organization, string $period): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.manage', $unit);

        return response()->json(['data' => $this->presenter->period(
            $this->calendar->savePeriod($company, $this->findPeriod($company, $unit, $period), $request->validated(), $request->user()),
        )]);
    }

    public function destroyPeriod(Request $request, string $organization, string $period): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.manage', $unit);
        $baseVersion = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->calendar->removePeriod($company, $this->findPeriod($company, $unit, $period), $baseVersion, $request->user());

        return response()->json(null, 204);
    }

    /** Holidays between two days (this year by default) at the unit and for the whole institution. */
    public function holidays(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.view', $unit);
        $today = $this->register->today($company);
        $from = $this->day((string) $request->query('from', $today->startOfYear()->toDateString()), 'from');
        $to = $this->day((string) $request->query('to', $today->endOfYear()->toDateString()), 'to');

        return response()->json(['data' => $this->calendar->holidays($company, $this->register->subtreeIds($unit), $from, $to)
            ->map(fn (Holiday $holiday) => $this->presenter->holiday($holiday))->values()]);
    }

    public function storeHoliday(HolidayRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.manage', $unit);
        $data = $request->validated();
        // From a campus, a holiday is that campus's; the whole institution's is added from the institution.
        if (($data['unit_id'] ?? null) === null && $unit->getKey() !== $company->getKey()) {
            $data['unit_id'] = $unit->getKey();
        }
        if (($data['unit_id'] ?? null) !== null && ! in_array($data['unit_id'], $this->register->subtreeIds($unit), true)) {
            throw ValidationException::withMessages(['unit_id' => __('student_attendance::attendance.validation.unit')]);
        }
        $levels = array_column($this->academic->levels($company), 'id');
        if (array_diff($data['level_ids'] ?? [], $levels) !== []) {
            throw ValidationException::withMessages(['level_ids' => __('student_attendance::attendance.validation.levels')]);
        }

        return response()->json(['data' => $this->presenter->holiday($this->calendar->addHoliday($company, $data, $request->user()))], 201);
    }

    public function destroyHoliday(Request $request, string $organization, string $holiday): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.manage', $unit);
        $found = $this->register->query(Holiday::class, $company)->whereKey($holiday)
            ->when($unit->getKey() !== $company->getKey(), fn ($query) => $query->whereIn('unit_id', $this->register->subtreeIds($unit)))
            ->first() ?? throw StudentAttendanceException::notFound('holiday');
        $this->calendar->removeHoliday($company, $found, $request->user());

        return response()->json(null, 204);
    }

    private function findPeriod(Organization $company, Organization $unit, string $id): Period
    {
        return $this->register->query(Period::class, $company)->whereKey($id)->whereIn('unit_id', $this->register->subtreeIds($unit))->first()
            ?? throw StudentAttendanceException::notFound('period');
    }
}
