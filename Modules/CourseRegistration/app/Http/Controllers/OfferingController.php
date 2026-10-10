<?php

namespace Modules\CourseRegistration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\CourseRegistration\Exceptions\RegistrationException;
use Modules\CourseRegistration\Http\Controllers\Concerns\FindsCampus;
use Modules\CourseRegistration\Http\RegistrationPresenter;
use Modules\CourseRegistration\Models\Offering;
use Modules\CourseRegistration\Models\RegistrationItem;
use Modules\CourseRegistration\Services\Campus;
use Modules\CourseRegistration\Services\Offerings;
use Modules\CourseRegistration\Services\Registrations;
use Modules\Education\Directory\AcademicDirectory;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Subjects offered in a session at the unit in the address and below
 * (read with course_registration.view, made and changed with .manage), the
 * session's registration window, and an offering's students. Someone who
 * only views (a teacher) sees the offerings they teach.
 */
class OfferingController extends Controller
{
    use FindsCampus;

    private const STAFF = ['course_registration.manage', 'course_registration.register', 'course_registration.approve', 'course_registration.record_outcome'];

    public function __construct(private Campus $campus, private Offerings $offerings, private Registrations $registrations, private RegistrationPresenter $presenter) {}

    /** What the screens start with: the limits, a session's window and what the reader may do. */
    public function setup(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('course_registration.view', $unit);
        $sessionId = $request->validate(['session_id' => ['nullable', 'string', 'size:26']])['session_id'] ?? null;

        $academic = app(AcademicDirectory::class);
        $units = $this->unitIds($unit);
        $hrm = class_exists(EmployeeDirectory::class) && app(ModuleResolver::class)->isEnabled('hrm', $company);

        return response()->json(['data' => [
            'rules' => $this->registrations->limits($company),
            'window' => $sessionId === null ? null : $this->presenter->window($this->registrations->window($company, $sessionId)),
            'today' => $this->campus->today($company)->toDateString(),
            'sessions' => $academic->sessions($company),
            'levels' => $academic->levels($company),
            // The campuses here (offerings and registrations belong to one).
            'campuses' => Organization::query()->whereKey($units)->whereNot('type', OrganizationType::Department->value)->orderBy('depth')->get()
                ->map(fn (Organization $campus) => ['id' => $campus->getKey(), 'name' => $campus->displayName()])->values(),
            // Teachers to choose from (people who offer subjects, with HRM on).
            'teachers' => $hrm && Gate::allows('course_registration.manage', $unit)
                ? array_map(fn ($record) => ['id' => $record->id, 'name' => $record->name, 'code' => $record->code], app(EmployeeDirectory::class)->inUnits($company, $units, $this->campus->today($company), 500))
                : [],
            'hrm' => $hrm,
            // Subjects to offer (people who offer them).
            'subjects' => Gate::allows('course_registration.manage', $unit) ? $academic->allSubjects($company) : [],
            'can' => array_combine(['manage', 'register', 'approve', 'record_outcome'], array_map(fn (string $permission) => Gate::allows($permission, $unit), self::STAFF)),
        ]]);
    }

    /** Students to register, found by name, code or phone (people who register them). */
    public function findStudents(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('course_registration.register', $unit);
        $term = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];
        $found = app(AcademicDirectory::class)->search($company, $term, $this->unitIds($unit), 20);

        return response()->json(['data' => array_map(fn (array $student) => array_intersect_key($student, array_flip(['id', 'code', 'name', 'name_local', 'unit_id', 'status'])), $found)]);
    }

    /** Sections of a session here, to register a whole one at once. */
    public function sections(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('course_registration.register', $unit);
        $sessionId = $request->validate(['session_id' => ['required', 'string', 'size:26']])['session_id'];

        return response()->json(['data' => app(AcademicDirectory::class)->sessionSections($company, $sessionId, $this->unitIds($unit))]);
    }

    public function saveWindow(Request $request, string $organization, string $session): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('course_registration.manage', $unit);
        $data = $request->validate([
            'opens_on' => ['required', 'date_format:Y-m-d'], 'closes_on' => ['required', 'date_format:Y-m-d'], 'add_drop_until' => ['required', 'date_format:Y-m-d'],
            'base_version' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->presenter->window($this->offerings->saveWindow($company, $session, $data, $request->user()))]);
    }

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('course_registration.view', $unit);
        $filters = $request->validate([
            'session_id' => ['required', 'string', 'size:26'], 'level_id' => ['nullable', 'string', 'size:26'],
            'subject_id' => ['nullable', 'string', 'size:26'], 'status' => ['nullable', 'in:'.implode(',', Offering::STATUSES)],
        ]);
        $query = $this->campus->query(Offering::class, $company)->where('session_id', $filters['session_id'])->whereIn('unit_id', $this->unitIds($unit))
            ->when($filters['level_id'] ?? null, fn ($inner, $level) => $inner->where('level_id', $level))
            ->when($filters['subject_id'] ?? null, fn ($inner, $subject) => $inner->where('subject_id', $subject))
            ->when($filters['status'] ?? null, fn ($inner, $status) => $inner->where('status', $status));
        $this->onlyOwn($query, $unit, $company, $request);
        $offerings = $query->orderBy('level_id')->orderBy('subject_id')->orderBy('group_name')->get();

        return response()->json(['data' => $this->present($company, $offerings)]);
    }

    public function store(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw RegistrationException::notCompanyUnit();
        }
        Gate::authorize('course_registration.manage', $unit);
        $data = $request->validate($this->rules(true));
        $data['unit_id'] = $this->unitFor($unit, $data['unit_id'] ?? null);
        $made = $this->offerings->save($company, null, null, $data, $request->user());

        return response()->json(['data' => $this->present($company, collect([$made]))[0]], 201);
    }

    public function update(Request $request, string $organization, string $offering): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Offering::class, $unit, $company, $offering, 'offering');
        Gate::authorize('course_registration.manage', $unit);
        $data = $request->validate($this->rules(false));
        $changed = $this->offerings->save($company, $found, (int) $data['base_version'], $data, $request->user());

        return response()->json(['data' => $this->present($company, collect([$changed]))[0]]);
    }

    /** Offer a level's curriculum in a session at a campus: one group of each subject not yet offered. */
    public function fromCurriculum(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw RegistrationException::notCompanyUnit();
        }
        Gate::authorize('course_registration.manage', $unit);
        $data = $request->validate([
            'unit_id' => ['nullable', 'string', 'size:26'], 'session_id' => ['required', 'string', 'size:26'], 'level_id' => ['required', 'string', 'size:26'],
            'group_name' => ['required', 'string', 'min:1', 'max:20'], 'capacity' => ['required', 'integer', 'min:1', 'max:2000'],
        ]);
        $made = $this->offerings->fromCurriculum($company, $this->unitFor($unit, $data['unit_id'] ?? null), $data['session_id'], $data['level_id'], $data['group_name'], (int) $data['capacity'], $request->user());

        return response()->json([
            'data' => $this->present($company, collect($made)),
            'message' => __('course_registration::registration.messages.offered', ['count' => count($made)]),
        ], 201);
    }

    /** An offering's students: holding a seat (by name), then waiting (in turn). */
    public function students(Request $request, string $organization, string $offering): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        /** @var Offering $found */
        $found = $this->recordIn(Offering::class, $unit, $company, $offering, 'offering');
        Gate::authorize('course_registration.view', $unit);
        $own = $this->campus->query(Offering::class, $company)->whereKey($found->getKey());
        $this->onlyOwn($own, $unit, $company, $request);
        if (! $own->exists()) {
            throw RegistrationException::notFound('offering');
        }
        $items = $this->campus->query(RegistrationItem::class, $company)->where('offering_id', $found->getKey())->whereIn('status', ['registered', 'waitlisted', 'withdrawn'])
            ->orderBy('waitlisted_at')->orderBy('id')->get();
        $students = app(AcademicDirectory::class)->students($company, $items->pluck('student_id')->unique()->values()->all());
        $rows = $items->map(fn (RegistrationItem $item) => [
            ...$this->presenter->item($item),
            'student' => array_intersect_key($students[$item->student_id] ?? [], array_flip(['id', 'code', 'name', 'name_local'])),
        ]);

        return response()->json(['data' => [
            'offering' => $this->present($company, collect([$found]))[0],
            'students' => $rows->sortBy(fn (array $row) => [$row['status'] === 'registered' ? 0 : ($row['status'] === 'waitlisted' ? 1 : 2), $row['status'] === 'waitlisted' ? $row['waitlisted_at'] : ($row['student']['name'] ?? '')])->values(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'unit_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'size:26'],
            'session_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'subject_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'level_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'group_name' => [$required, 'string', 'min:1', 'max:20'],
            'kind' => ['sometimes', 'in:'.implode(',', Offering::KINDS)],
            'teacher_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'capacity' => [$required, 'integer', 'min:1', 'max:2000'],
            'credits_centi' => ['sometimes', 'integer', 'min:0', 'max:3000'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'in:'.implode(',', Offering::STATUSES)],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }

    /** The campus asked for (here or below), else the one in the address. */
    private function unitFor(Organization $unit, ?string $asked): string
    {
        if ($asked === null || $asked === '') {
            return $unit->getKey();
        }
        if (! in_array($asked, $this->unitIds($unit), true)) {
            throw RegistrationException::notFound('unit');
        }

        return $asked;
    }

    /** Someone who only views (a teacher) sees the offerings they teach (HRM links their login to them). */
    private function onlyOwn($query, Organization $unit, Organization $company, Request $request): void
    {
        foreach (self::STAFF as $permission) {
            if (Gate::allows($permission, $unit)) {
                return;
            }
        }
        $employee = class_exists(EmployeeDirectory::class) && app(ModuleResolver::class)->isEnabled('hrm', $company)
            ? app(EmployeeDirectory::class)->forUser($company, $request->user()) : null;
        $query->where('teacher_id', $employee?->id ?? '-');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Offering>  $offerings
     * @return list<array<string, mixed>>
     */
    private function present(Organization $company, $offerings): array
    {
        $subjects = $this->presenter->subjectsOf($company, $offerings);
        $counts = $this->campus->query(RegistrationItem::class, $company)->whereIn('offering_id', $offerings->pluck('id'))->whereIn('status', RegistrationItem::ACTIVE)
            ->get(['offering_id', 'status'])->groupBy('offering_id');

        return $offerings->map(fn (Offering $offering) => $this->presenter->offering($offering, $subjects,
            $counts->get($offering->getKey())?->where('status', 'registered')->count() ?? 0,
            $counts->get($offering->getKey())?->where('status', 'waitlisted')->count() ?? 0))->values()->all();
    }
}
