<?php

namespace Modules\StudentAttendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Exceptions\StudentAttendanceException;
use Modules\StudentAttendance\Http\AttendancePresenter;
use Modules\StudentAttendance\Http\Controllers\Concerns\AttendancePlace;
use Modules\StudentAttendance\Http\Requests\SheetRequest;
use Modules\StudentAttendance\Models\Mark;
use Modules\StudentAttendance\Models\Period;
use Modules\StudentAttendance\Services\Calendar;
use Modules\StudentAttendance\Services\Register;
use Modules\StudentAttendance\Services\Sheets;

/**
 * Attendance sheets at a unit. Reading needs student_attendance.view (a
 * teacher sees their own sections, as Education lets them see students);
 * saving needs .take for one's own section or subject, or .manage. The
 * server checks the section, the day and the period on every save.
 */
class SheetController extends Controller
{
    use AttendancePlace;

    public function __construct(
        private Register $register,
        private Sheets $sheets,
        private Calendar $calendar,
        private AcademicDirectory $academic,
        private AttendancePresenter $presenter,
    ) {}

    /** What the screens need once: what the person may do, the rules, their sections (open sessions) and the periods. */
    public function setup(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.view', $unit);
        $units = $this->register->subtreeIds($unit);
        $sections = $this->sections($company, $unit, $request);
        $levels = collect($this->academic->levels($company))->keyBy('id');
        $byPeriod = $sections->mapWithKeys(fn (array $section) => [$section['id'] => $this->sheets->byPeriod($company, $section['unit_id'])]);
        $subjectIds = [];
        $mine = [];
        foreach ($sections as $section) {
            $mine[$section['id']] = $byPeriod[$section['id']] ? $this->sheets->mySubjects($company, $request->user(), $section) : [];
            $subjectIds = [...$subjectIds, ...array_column($this->academic->curriculum($company, $section['level_id'], $section['session_id']), 'subject_id')];
        }

        return response()->json(['data' => [
            'can' => [
                'view' => true,
                'take' => Gate::allows('student_attendance.take', $unit) || Gate::allows('student_attendance.manage', $unit),
                'manage' => Gate::allows('student_attendance.manage', $unit),
            ],
            'rules' => [
                'by_period' => (bool) $this->register->rule('student_attendance.by_period', $company, $unit->getKey()),
                'edit_days' => (int) $this->register->rule('student_attendance.edit_days', $company, $unit->getKey()),
                'late_counts_present' => (bool) $this->register->rule('student_attendance.late_counts_present', $company, $unit->getKey()),
                'period_present_share_bp' => (int) $this->register->rule('student_attendance.period_present_share_bp', $company, $unit->getKey()),
                'weekend_days' => array_values((array) $this->register->rule('student_attendance.weekend_days', $company, $unit->getKey())),
            ],
            'statuses' => Mark::STATUSES,
            'today' => $this->register->today($company)->toDateString(),
            'sections' => $sections->map(fn (array $section) => [
                'id' => $section['id'], 'name' => $section['name'], 'unit_id' => $section['unit_id'], 'session_id' => $section['session_id'],
                'level_id' => $section['level_id'], 'level' => $levels[$section['level_id']]['name'] ?? null, 'students' => $section['students'],
                'by_period' => $byPeriod[$section['id']],
                'class_teacher' => $this->sheets->isClassTeacher($company, $request->user(), $section),
                'my_subjects' => $mine[$section['id']],
            ])->values(),
            'subjects' => (object) collect($this->academic->subjects($company, array_values(array_unique($subjectIds))))
                ->map(fn (array $subject) => ['code' => $subject['code'], 'name' => $subject['name']])->all(),
            'periods' => $this->calendar->periods($company, $units, activeOnly: true)->map(fn (Period $period) => $this->presenter->period($period))->values(),
        ]]);
    }

    /** A day at the unit: each section the person sees with the sheets taken (by slot) and whether the day is off. */
    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.view', $unit);
        $day = $this->day((string) $request->query('date', $this->register->today($company)->toDateString()));
        $sections = $this->sections($company, $unit, $request);
        $sheets = $this->sheets->sheetsOn($company, $sections->pluck('id')->all(), $day);

        return response()->json([
            'data' => $sections->map(fn (array $section) => [
                'section_id' => $section['id'],
                'students' => $section['students'],
                'by_period' => $this->sheets->byPeriod($company, $section['unit_id']),
                'day_off' => $this->calendar->dayOff($company, $section['unit_id'], $section['level_id'], $day),
                'sheets' => collect($sheets[$section['id']] ?? [])->map(fn ($sheet) => $this->presenter->sheet($sheet))->values(),
            ])->values(),
            'meta' => ['date' => $day->toDateString()],
        ]);
    }

    /** A section's sheet of a day (and period): its students with their state. */
    public function show(Request $request, string $organization, string $section, string $date): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.view', $unit);
        $this->visibleOrFail($company, $unit, $request, $section);
        $periodId = $request->validate(['period_id' => ['nullable', 'string', 'size:26']])['period_id'] ?? null;

        return response()->json(['data' => $this->opened($this->sheets->open($company, $unit, $request->user(), $section, $this->day($date), $periodId))]);
    }

    /** Save a section's sheet of a day (201 when it is new; a resend with the same op_id answers 200 with it). */
    public function save(SheetRequest $request, string $organization, string $section, string $date): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if (! Gate::allows('student_attendance.take', $unit) && ! Gate::allows('student_attendance.manage', $unit)) {
            Gate::authorize('student_attendance.take', $unit);
        }
        $day = $this->day($date);
        [$sheet, $created] = $this->sheets->save($company, $unit, $request->user(), $section, $day, $request->validated());

        return response()->json([
            'data' => $this->opened($this->sheets->open($company, $unit, $request->user(), $sheet->section_id, $sheet->taken_on, $sheet->period_id)),
        ], $created ? 201 : 200);
    }

    /**
     * Sections of the open sessions at the unit that the person sees.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function sections(Organization $company, Organization $unit, Request $request): Collection
    {
        $units = $this->register->subtreeIds($unit);
        $visible = $this->sheets->visibleSections($company, $unit, $request->user());

        return collect($this->academic->sessions($company))->where('status', 'open')
            ->flatMap(fn (array $session) => $this->academic->sessionSections($company, $session['id'], $units))
            ->when($visible !== null, fn (Collection $all) => $all->whereIn('id', $visible))
            ->values();
    }

    private function visibleOrFail(Organization $company, Organization $unit, Request $request, string $sectionId): void
    {
        $visible = $this->sheets->visibleSections($company, $unit, $request->user());
        if ($visible !== null && ! in_array($sectionId, $visible, true)) {
            throw StudentAttendanceException::notFound('section');
        }
    }

    /**
     * @param  array<string, mixed>  $opened
     * @return array<string, mixed>
     */
    private function opened(array $opened): array
    {
        $section = $opened['section'];

        return [
            'section' => ['id' => $section['id'], 'name' => $section['name'], 'unit_id' => $section['unit_id'], 'session_id' => $section['session_id'], 'level_id' => $section['level_id']],
            'by_period' => $opened['by_period'],
            'period' => $opened['period'] === null ? null : $this->presenter->period($opened['period']),
            'day_off' => $opened['day_off'],
            'may_take' => $opened['may_take'],
            'my_subjects' => $opened['my_subjects'],
            'sheet' => $opened['sheet'] === null ? null : $this->presenter->sheet($opened['sheet']),
            'students' => $opened['students'],
        ];
    }
}
