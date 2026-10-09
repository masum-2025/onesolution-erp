<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Http\EducationPresenter;
use Modules\Education\Http\Requests\AdmissionRequest;
use Modules\Education\Models\Admission;
use Modules\Education\Services\Admissions;
use Modules\Education\Services\Education;
use Modules\Education\Services\Enrollments;

/**
 * Applications at the unit in the address and the campuses below, all with
 * education.admit: listed, taken, changed while undecided, moved through
 * the decisions, and admitted (which makes the student). The applicant's
 * date of birth and national ids only with education.view_sensitive.
 */
class AdmissionController extends Controller
{
    use FindsEducation;

    public function __construct(private Education $education, private Admissions $admissions, private Enrollments $enrollments, private EducationPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.admit', $unit);
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Admission::STATUSES)], 'program_id' => ['nullable', 'string', 'size:26'],
            'session_id' => ['nullable', 'string', 'size:26'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $page = $this->education->query(Admission::class, $company)->whereIn('unit_id', $this->unitIds($unit))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['program_id'] ?? null, fn ($query, $program) => $query->where('program_id', $program))
            ->when($filters['session_id'] ?? null, fn ($query, $session) => $query->where('session_id', $session))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where('number', 'like', "%{$term}%"))
            ->orderByDesc('created_at')->paginate(PerPage::from($request, 50));
        $sensitive = Gate::allows('education.view_sensitive', $unit);

        return response()->json([
            'data' => collect($page->items())->map(fn (Admission $admission) => $this->presenter->admission($company, $admission, $sensitive))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->admission($organization, $admission);

        return response()->json(['data' => $this->presenter->admission($company, $found, Gate::allows('education.view_sensitive', $unit))]);
    }

    public function store(AdmissionRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw EducationException::notCompanyUnit();
        }
        Gate::authorize('education.admit', $unit);
        $data = $request->validated();
        $made = $this->admissions->create($company, $this->unitFor($unit, $data['unit_id'] ?? null), $data, $request->user());

        return response()->json(['data' => $this->presenter->admission($company, $made, Gate::allows('education.view_sensitive', $unit))], $made->wasRecentlyCreated ? 201 : 200);
    }

    public function update(AdmissionRequest $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->admission($organization, $admission);
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->admission($company, $this->admissions->update($company, $found, (int) $data['base_version'], $data, $request->user()), Gate::allows('education.view_sensitive', $unit))]);
    }

    /** A decision: test, offered, rejected or withdrawn. */
    public function step(Request $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->admission($organization, $admission);
        $data = $request->validate([
            'base_version' => ['required', 'integer', 'min:1'], 'status' => ['required', 'in:test,offered,rejected,withdrawn'], 'note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->presenter->admission($company, $this->admissions->move($company, $found, (int) $data['base_version'], $data['status'], $data['note'] ?? null, $request->user()), Gate::allows('education.view_sensitive', $unit))]);
    }

    public function admit(Request $request, string $organization, string $admission): JsonResponse
    {
        [$unit, $company, $found] = $this->admission($organization, $admission);
        $data = $request->validate([
            'base_version' => ['required', 'integer', 'min:1'], 'section_id' => ['nullable', 'string', 'size:26'], 'batch_id' => ['nullable', 'string', 'size:26'],
            'category_id' => ['nullable', 'string', 'size:26'], 'birth_registration_no' => ['nullable', 'string', 'max:40'], 'admitted_on' => ['nullable', 'date_format:Y-m-d'],
            'extra' => ['sometimes', 'array'],
        ]);
        if (! empty($data['birth_registration_no'])) {
            Gate::authorize('education.view_sensitive', $unit);
        }
        $student = $this->admissions->admit($company, $found, (int) $data['base_version'], $data, $request->user());

        return response()->json(['data' => $this->presenter->student($company, $student, Gate::allows('education.view_sensitive', $unit), $this->enrollments->current($company, $student->getKey())), 'message' => __('education::education.messages.admitted', ['code' => $student->code])], 201);
    }

    /** @return array{0: Organization, 1: Organization, 2: Admission} */
    private function admission(string $organization, string $admission): array
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Admission::class, $unit, $company, $admission, 'admission');
        Gate::authorize('education.admit', $unit);

        return [$unit, $company, $found];
    }
}
