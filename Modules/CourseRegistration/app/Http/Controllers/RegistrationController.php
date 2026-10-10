<?php

namespace Modules\CourseRegistration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\CourseRegistration\Exceptions\RegistrationException;
use Modules\CourseRegistration\Http\Controllers\Concerns\FindsCampus;
use Modules\CourseRegistration\Http\RegistrationPresenter;
use Modules\CourseRegistration\Models\Offering;
use Modules\CourseRegistration\Models\Registration;
use Modules\CourseRegistration\Models\RegistrationItem;
use Modules\CourseRegistration\Services\Campus;
use Modules\CourseRegistration\Services\Registrations;
use Modules\Education\Directory\AcademicDirectory;

/**
 * Students' registrations at the unit in the address and below: read
 * (course_registration.view), subjects added and dropped, handed in and a
 * whole section registered (.register), approved or sent back (.approve),
 * outcomes recorded (.record_outcome).
 */
class RegistrationController extends Controller
{
    use FindsCampus;

    public function __construct(private Campus $campus, private Registrations $registrations, private AcademicDirectory $academic, private RegistrationPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('course_registration.view', $unit);
        $filters = $request->validate([
            'session_id' => ['required', 'string', 'size:26'], 'status' => ['nullable', 'in:'.implode(',', Registration::STATUSES)],
            'overload' => ['nullable', 'boolean'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        // By the student's name, code or phone.
        $found = ($filters['q'] ?? '') === '' ? null : array_column($this->academic->search($company, $filters['q'], $this->unitIds($unit), 200), 'id');
        $base = fn () => $this->campus->query(Registration::class, $company)->where('session_id', $filters['session_id'])->whereIn('unit_id', $this->unitIds($unit))
            ->when($found !== null, fn ($query) => $query->whereIn('student_id', $found));
        $page = $base()->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(isset($filters['overload']), fn ($query) => $query->where('overload', filter_var($filters['overload'], FILTER_VALIDATE_BOOL)))
            ->orderByDesc('submitted_at')->orderBy('id')->paginate(PerPage::from($request, 50));
        $students = $this->academic->students($company, collect($page->items())->pluck('student_id')->all());

        return response()->json([
            'data' => collect($page->items())->map(fn (Registration $registration) => $this->presenter->registration($registration, student: $students[$registration->student_id] ?? null))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'counts' => (object) $base()->get(['status'])->countBy('status')->all()],
        ]);
    }

    /** A student's registration for a session (made as a draft the first time someone who registers opens it). */
    public function open(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('course_registration.register', $unit);
        $data = $request->validate(['student_id' => ['required', 'string', 'size:26'], 'session_id' => ['required', 'string', 'size:26']]);
        $student = $this->academic->student($company, $data['student_id']);
        if ($student === null || ! in_array($student['unit_id'], $this->unitIds($unit), true)) {
            throw RegistrationException::notFound('student');
        }
        $registration = $this->registrations->for($company, $data['student_id'], $data['session_id'], $request->user());

        return response()->json(['data' => $this->detail($company, $registration)], $registration->wasRecentlyCreated ? 201 : 200);
    }

    public function show(string $organization, string $registration): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Registration::class, $unit, $company, $registration, 'registration');
        Gate::authorize('course_registration.view', $unit);

        return response()->json(['data' => $this->detail($company, $found)]);
    }

    public function add(Request $request, string $organization, string $registration): JsonResponse
    {
        [$unit, $company, $found] = $this->registration($organization, $registration, 'course_registration.register');
        $data = $request->validate(['offering_id' => ['required', 'string', 'size:26'], 'op_id' => ['nullable', 'string', 'max:64']]);
        $item = $this->registrations->add($company, $found, $data['offering_id'], $request->user(), opId: $data['op_id'] ?? null);

        return response()->json(['data' => $this->detail($company, $found->refresh()), 'item_id' => $item->getKey()], 201);
    }

    public function drop(Request $request, string $organization, string $item): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->campus->find(RegistrationItem::class, $company, $item, 'item');
        $registration = $this->recordIn(Registration::class, $unit, $company, $found->registration_id, 'item');
        Gate::authorize('course_registration.register', $unit);
        $data = $request->validate(['reason' => ['nullable', 'string', 'min:3', 'max:300']]);
        $this->registrations->drop($company, $found, $data['reason'] ?? null, $request->user());

        return response()->json(['data' => $this->detail($company, $registration->refresh())]);
    }

    public function submit(Request $request, string $organization, string $registration): JsonResponse
    {
        [, $company, $found] = $this->registration($organization, $registration, 'course_registration.register');

        return response()->json(['data' => $this->detail($company, $this->registrations->submit($company, $found, $request->user()))]);
    }

    public function approve(Request $request, string $organization, string $registration): JsonResponse
    {
        [, $company, $found] = $this->registration($organization, $registration, 'course_registration.approve');
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->detail($company, $this->registrations->approve($company, $found, (int) $data['base_version'], $request->user()))]);
    }

    public function sendBack(Request $request, string $organization, string $registration): JsonResponse
    {
        [, $company, $found] = $this->registration($organization, $registration, 'course_registration.approve');
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'note' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $this->detail($company, $this->registrations->sendBack($company, $found, (int) $data['base_version'], $data['note'], $request->user()))]);
    }

    /** Every student of a section for the compulsory subjects offered to their class. */
    public function registerSection(Request $request, string $organization, string $section): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('course_registration.register', $unit);
        $found = $this->academic->section($company, $section);
        if ($found === null || ! in_array($found['unit_id'], $this->unitIds($unit), true)) {
            throw RegistrationException::notFound('section');
        }

        return response()->json(['data' => $this->registrations->registerSection($company, $section, $request->user())]);
    }

    public function outcome(Request $request, string $organization, string $item): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->campus->find(RegistrationItem::class, $company, $item, 'item');
        $this->recordIn(Registration::class, $unit, $company, $found->registration_id, 'item');
        Gate::authorize('course_registration.record_outcome', $unit);
        $data = $request->validate(['outcome' => ['required', 'in:completed,failed,incomplete']]);

        return response()->json(['data' => $this->presenter->item($this->registrations->recordOutcome($company, $found, $data['outcome'], $request->user()))]);
    }

    /**
     * @return array{0: Organization, 1: Organization, 2: Registration}
     */
    private function registration(string $organization, string $registration, string $permission): array
    {
        [$unit, $company] = $this->workplace($organization);
        /** @var Registration $found */
        $found = $this->recordIn(Registration::class, $unit, $company, $registration, 'registration');
        Gate::authorize($permission, $unit);

        return [$unit, $company, $found];
    }

    /** @return array<string, mixed> */
    private function detail(Organization $company, Registration $registration): array
    {
        $items = $this->registrations->items($company, $registration);
        $offerings = $this->campus->query(Offering::class, $company)->whereKey($items->pluck('offering_id'))->get()->keyBy('id')->all();

        return [
            ...$this->presenter->registration($registration, $items, $this->presenter->subjectsOf($company, $items), $this->academic->student($company, $registration->student_id), $offerings),
            'window' => $this->presenter->window($this->registrations->window($company, $registration->session_id)),
        ];
    }
}
