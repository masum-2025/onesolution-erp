<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Http\EducationPresenter;
use Modules\Education\Http\Requests\GuardianRequest;
use Modules\Education\Http\Requests\StudentRequest;
use Modules\Education\Models\Admission;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Guardian;
use Modules\Education\Models\Student;
use Modules\Education\Models\StudentGuardian;
use Modules\Education\Services\Access;
use Modules\Education\Services\Education;
use Modules\Education\Services\Enrollments;
use Modules\Education\Services\Fields;
use Modules\Education\Services\StudentImporter;
use Modules\Education\Services\Students;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Students of the unit in the address and the campuses below: read
 * (education.view; a teacher only their own sections' students by the rule
 * education.teacher_scope), made directly (education.admit), changed, made
 * to leave, given guardians and a photo (education.edit_students).
 * Sensitive details are read and written only with education.view_sensitive.
 */
class StudentController extends Controller
{
    use FindsEducation;

    public function __construct(
        private Education $education,
        private Students $students,
        private Enrollments $enrollments,
        private Access $access,
        private EducationPresenter $presenter,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:'.implode(',', Student::STATUSES)],
            'program_id' => ['nullable', 'string', 'size:26'], 'session_id' => ['nullable', 'string', 'size:26'], 'level_id' => ['nullable', 'string', 'size:26'],
            'section_id' => ['nullable', 'string', 'size:26'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            // Studying now but in no section yet (in the session given, else in any).
            'unplaced' => ['nullable', 'boolean'],
        ]);
        $sections = $this->access->sections($company, $unit, $request->user());
        $query = $this->education->query(Student::class, $company)->whereIn('unit_id', $this->unitIds($unit));
        $this->access->restrict($query, $company, $sections);

        if (($filters['q'] ?? '') !== '') {
            $term = $filters['q'];
            $phone = preg_replace('/\D/', '', strtr($term, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
            $query->where(fn ($inner) => $inner->where('name', 'like', "%{$term}%")->orWhere('name_local', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")
                ->when(strlen((string) $phone) >= 5, fn ($or) => $or->orWhere('phone', 'like', "%{$phone}%")));
        }
        $query->when($filters['status'] ?? null, fn ($inner, $status) => $inner->where('status', $status))
            ->when($filters['program_id'] ?? null, fn ($inner, $program) => $inner->where('program_id', $program));
        $placed = array_filter(array_intersect_key($filters, array_flip(['session_id', 'level_id', 'section_id'])));
        $unplaced = filter_var($filters['unplaced'] ?? false, FILTER_VALIDATE_BOOL);
        if ($placed !== [] || $unplaced) {
            $enrolled = $this->education->query(Enrollment::class, $company)->where($placed)
                ->when($unplaced, fn ($inner) => $inner->where('status', 'active')->whereNull('section_id'))
                ->pluck('student_id')->all();
            $query->whereIn('id', $enrolled);
        }

        $page = $query->orderBy('name')->orderBy('code')->paginate(PerPage::from($request, 50));
        $current = $this->education->query(Enrollment::class, $company)->whereIn('student_id', collect($page->items())->pluck('id'))->where('status', 'active')->get()->keyBy('student_id');
        $sensitive = Gate::allows('education.view_sensitive', $unit);

        return response()->json([
            'data' => collect($page->items())->map(fn (Student $student) => $this->presenter->student($company, $student, $sensitive, $current[$student->getKey()] ?? null))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, string $organization, string $student): JsonResponse
    {
        [$unit, $company, $found] = $this->student($request, $organization, $student, 'education.view');
        $sensitive = Gate::allows('education.view_sensitive', $unit);
        $links = $this->education->query(StudentGuardian::class, $company)->where('student_id', $found->getKey())->get()->keyBy('guardian_id');
        $guardians = $this->education->query(Guardian::class, $company)->whereKey($links->keys())->get();
        if ($sensitive) {
            app(AuditLogger::class)->record('education.student_viewed_sensitive', $found, actor: $request->user(), organizationId: $company->getKey());
        }

        return response()->json(['data' => [
            ...$this->presenter->student($company, $found, $sensitive, $this->enrollments->current($company, $found->getKey())),
            'guardians' => $guardians->map(fn (Guardian $guardian) => $this->presenter->guardian($company, $guardian, $links[$guardian->getKey()], $sensitive))
                ->sortByDesc('is_primary')->values(),
            'enrollments' => $this->education->query(Enrollment::class, $company)->where('student_id', $found->getKey())->orderByDesc('started_on')->get()
                ->map(fn (Enrollment $enrollment) => $this->presenter->enrollment($enrollment))->values(),
            'admission' => ($admission = $this->education->query(Admission::class, $company)->where('student_id', $found->getKey())->first()) === null
                ? null : ['id' => $admission->getKey(), 'number' => $admission->number, 'source' => $admission->source],
        ]]);
    }

    public function store(StudentRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw EducationException::notCompanyUnit();
        }
        Gate::authorize('education.admit', $unit);
        $data = $request->validated();
        $this->guardSensitive($unit, $company, $data, 'student');
        $made = $this->students->create($company, $this->unitFor($unit, $data['unit_id'] ?? null), $data, $request->user());

        return response()->json(['data' => $this->presenter->student($company, $made, Gate::allows('education.view_sensitive', $unit), $this->enrollments->current($company, $made->getKey()))], $made->wasRecentlyCreated ? 201 : 200);
    }

    public function update(StudentRequest $request, string $organization, string $student): JsonResponse
    {
        [$unit, $company, $found] = $this->student($request, $organization, $student, 'education.edit_students');
        $data = $request->validated();
        $this->guardSensitive($unit, $company, $data, 'student');
        $changed = $this->students->update($company, $found, (int) $data['base_version'], $data, $request->user());

        return response()->json(['data' => $this->presenter->student($company, $changed, Gate::allows('education.view_sensitive', $unit), $this->enrollments->current($company, $changed->getKey()))]);
    }

    /**
     * Rows read from a spreadsheet on the screen: checked (commit false) or
     * made (commit true), placed in the session and level chosen for the file.
     */
    public function import(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw EducationException::notCompanyUnit();
        }
        Gate::authorize('education.admit', $unit);
        $data = $request->validate([
            'unit_id' => ['nullable', 'string', 'size:26'],
            'program_id' => ['required', 'string', 'size:26'], 'session_id' => ['required', 'string', 'size:26'],
            'level_id' => ['required', 'string', 'size:26'], 'section_id' => ['nullable', 'string', 'size:26'],
            'commit' => ['required', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:'.StudentImporter::MAX_ROWS],
            'rows.*' => ['array'],
            'rows.*.*' => ['nullable', 'string', 'max:300'],
        ]);
        // Private columns only from people who may see them.
        $private = array_merge(StudentImporter::SENSITIVE, app(Fields::class)->of($company, 'student')->where('is_sensitive', true)->pluck('key')->all());
        foreach ($data['rows'] as $row) {
            if (array_filter(array_intersect_key($row, array_flip($private)), fn ($value) => $value !== null && $value !== '') !== []) {
                Gate::authorize('education.view_sensitive', $unit);
                break;
            }
        }
        $unitId = $this->unitFor($unit, $data['unit_id'] ?? null);
        $result = app(StudentImporter::class)->import($company, $unitId, array_intersect_key($data, array_flip(['program_id', 'session_id', 'level_id', 'section_id'])), $data['rows'], (bool) $data['commit'], $request->user());

        return response()->json(['data' => $result]);
    }

    public function leave(Request $request, string $organization, string $student): JsonResponse
    {
        [$unit, $company, $found] = $this->student($request, $organization, $student, 'education.edit_students');
        $data = $request->validate([
            'base_version' => ['required', 'integer', 'min:1'], 'status' => ['required', 'in:left,graduated'],
            'reason' => ['required', 'string', 'min:3', 'max:300'], 'on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $changed = $this->students->leave($company, $found, (int) $data['base_version'], $data['status'], $data['reason'], $data['on'] ?? null, $request->user());

        return response()->json(['data' => $this->presenter->student($company, $changed, Gate::allows('education.view_sensitive', $unit))]);
    }

    public function linkGuardian(GuardianRequest $request, string $organization, string $student): JsonResponse
    {
        [$unit, $company, $found] = $this->student($request, $organization, $student, 'education.edit_students');
        $data = $request->validated();
        $this->guardSensitive($unit, $company, $data, 'guardian');
        $link = $this->students->link($company, $found, $data, $request->user());
        $guardian = $this->education->find(Guardian::class, $company, $link->guardian_id, 'guardian');

        return response()->json(['data' => $this->presenter->guardian($company, $guardian, $link, Gate::allows('education.view_sensitive', $unit))], 201);
    }

    public function unlinkGuardian(Request $request, string $organization, string $student, string $guardian): JsonResponse
    {
        [, $company, $found] = $this->student($request, $organization, $student, 'education.edit_students');
        $this->students->unlink($company, $found, $guardian, $request->user());

        return response()->json(['data' => null, 'message' => __('education::education.messages.guardian_unlinked')]);
    }

    public function updateGuardian(GuardianRequest $request, string $organization, string $student, string $guardian): JsonResponse
    {
        [$unit, $company, $found] = $this->student($request, $organization, $student, 'education.edit_students');
        $link = $this->education->query(StudentGuardian::class, $company)->where('student_id', $found->getKey())->where('guardian_id', $guardian)->first() ?? throw EducationException::notFound('guardian');
        $data = $request->validated();
        $this->guardSensitive($unit, $company, $data, 'guardian');
        $changed = $this->students->updateGuardian($company, $this->education->find(Guardian::class, $company, $guardian, 'guardian'), (int) $data['base_version'], $data, $request->user());

        return response()->json(['data' => $this->presenter->guardian($company, $changed, $link, Gate::allows('education.view_sensitive', $unit))]);
    }

    public function storePhoto(Request $request, string $organization, string $student): JsonResponse
    {
        [$unit, $company, $found] = $this->student($request, $organization, $student, 'education.edit_students');
        $request->validate(['photo' => ['required', 'file', 'max:'.Students::PHOTO_MAX_KB]]);
        $changed = $this->students->storePhoto($company, $found, $request->file('photo'), $request->user());

        return response()->json(['data' => $this->presenter->student($company, $changed, Gate::allows('education.view_sensitive', $unit))]);
    }

    /** The photo, through a short-lived signed link only (routes/web.php). */
    public function photo(string $organization, string $student): StreamedResponse
    {
        $company = Organization::query()->find($organization);
        $found = $company === null ? null : Student::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization)->whereKey($student)->first();
        abort_if($found === null || $found->photo_path === null || ! Storage::disk('local')->exists($found->photo_path), 404);

        return Storage::disk('local')->response($found->photo_path, null, [
            'Content-Type' => Storage::disk('local')->mimeType($found->photo_path),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The unit, institution and a student the reader may see there, after checking the permission.
     *
     * @return array{0: Organization, 1: Organization, 2: Student}
     */
    private function student(Request $request, string $organization, string $student, string $permission): array
    {
        [$unit, $company] = $this->workplace($organization);
        /** @var Student $found */
        $found = $this->recordIn(Student::class, $unit, $company, $student, 'student');
        Gate::authorize($permission, $unit);
        if (! $this->access->canSeeStudent($company, $this->access->sections($company, $unit, $request->user()), $found->getKey())) {
            throw EducationException::notFound('student');
        }

        return [$unit, $company, $found];
    }

    /**
     * Sensitive details are sent only by people who may see them.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardSensitive(Organization $unit, Organization $company, array $data, string $entity): void
    {
        $fields = app(Fields::class);
        $secret = fn (string $of, array $extra) => array_intersect_key($extra, array_flip($fields->of($company, $of)->where('is_sensitive', true)->pluck('key')->all()));
        $sensitive = [...array_intersect_key($data, array_flip([...Students::SENSITIVE, 'national_id'])), ...$secret($entity, (array) ($data['extra'] ?? []))];
        foreach ((array) ($data['guardians'] ?? []) as $guardian) {
            if (! empty($guardian['national_id']) || $secret('guardian', (array) ($guardian['extra'] ?? [])) !== []) {
                $sensitive['guardians'] = true;
            }
        }
        if ($sensitive !== [] && ! Gate::allows('education.view_sensitive', $unit)) {
            Gate::authorize('education.view_sensitive', $unit);
        }
    }
}
