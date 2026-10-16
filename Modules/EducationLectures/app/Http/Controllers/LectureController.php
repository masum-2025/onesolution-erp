<?php

namespace Modules\EducationLectures\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationLectures\Exceptions\LectureException;
use Modules\EducationLectures\Http\LecturePresenter;
use Modules\EducationLectures\Http\Requests\LectureRequest;
use Modules\EducationLectures\Models\Lecture;
use Modules\EducationLectures\Models\LectureFile;
use Modules\EducationLectures\Services\Classroom;
use Modules\EducationLectures\Services\Lectures;

/**
 * Lectures at a unit for staff. Reading needs education_lectures.view:
 * who manages lectures sees every one; others see the published lectures of
 * their own sections (as Education lets them see students) and everything
 * they posted. Posting needs education_lectures.post for a subject one
 * teaches there (or .manage); the server checks each step again.
 */
class LectureController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private Classroom $classroom,
        private Lectures $lectures,
        private AcademicDirectory $academic,
        private LecturePresenter $presenter,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** What the screens need once: what the person may do, the rules, and the sections and subjects they may post for. */
    public function setup(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_lectures.view', $unit);
        $context = $this->contexts->forOrganization($unit);
        $units = $this->classroom->subtreeIds($unit);
        $session = collect($this->academic->sessions($company))->firstWhere('status', 'open');
        $visible = $this->lectures->visibleSections($company, $unit, $request->user());
        $sections = $session === null ? collect() : collect($this->academic->sessionSections($company, $session['id'], $units))
            ->when($visible !== null, fn (Collection $all) => $all->whereIn('id', $visible))->values();
        $levels = collect($this->academic->levels($company))->keyBy('id');
        $curricula = [];
        $subjectIds = [];
        foreach ($sections as $section) {
            $curricula[$section['id']] = array_values(array_unique(array_column($this->academic->curriculum($company, $section['level_id'], $section['session_id']), 'subject_id')));
            $subjectIds = [...$subjectIds, ...$curricula[$section['id']]];
        }
        $names = $this->academic->subjects($company, array_values(array_unique($subjectIds)));

        return response()->json(['data' => [
            'can' => [
                'view' => true,
                'post' => Gate::allows('education_lectures.post', $unit) || Gate::allows('education_lectures.manage', $unit),
                'manage' => Gate::allows('education_lectures.manage', $unit),
            ],
            'rules' => [
                'approval_required' => (bool) $this->rules->get('education_lectures.approval_required', $context),
                'max_file_kb' => (int) $this->rules->get('education_lectures.max_file_kb', $context),
                'max_files' => (int) $this->rules->get('education_lectures.max_files', $context),
                'video_sites' => array_values((array) $this->rules->get('education_lectures.video_hosts', $context)),
            ],
            'file_types' => array_values(array_unique(Lectures::MIME_TYPES)),
            'today' => $this->classroom->today($company)->toDateString(),
            'session' => $session === null ? null : ['id' => $session['id'], 'name' => $session['name']],
            'sections' => $sections->map(fn (array $section) => [
                'id' => $section['id'], 'name' => $section['name'], 'level_id' => $section['level_id'],
                'level' => $levels[$section['level_id']]['name'] ?? null,
                'subjects' => collect($curricula[$section['id']])->filter(fn (string $id) => isset($names[$id]))->map(fn (string $id) => [
                    'id' => $id, 'code' => $names[$id]['code'], 'name' => $names[$id]['name'],
                    'may_post' => $this->lectures->mayPost($company, $unit, $request->user(), $section['id'], $id),
                ])->values(),
            ])->values(),
        ]]);
    }

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_lectures.view', $unit);
        $filters = $request->validate([
            'section_id' => ['nullable', 'string', 'size:26'], 'subject_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'in:'.implode(',', Lecture::STATUSES)], 'mine' => ['nullable', 'boolean'],
            // One student's lectures (their section now), e.g. for their page in Education.
            'student_id' => ['nullable', 'string', 'size:26'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $studentSection = isset($filters['student_id']) ? ($this->academic->enrollment($company, $filters['student_id'])['section_id'] ?? '') : null;
        $page = $this->visible($company, $unit, $request)
            ->when($studentSection !== null, fn ($query) => $query->where('section_id', $studentSection))
            ->when($filters['section_id'] ?? null, fn ($query, $id) => $query->where('section_id', $id))
            ->when($filters['subject_id'] ?? null, fn ($query, $id) => $query->where('subject_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(filter_var($filters['mine'] ?? false, FILTER_VALIDATE_BOOL), fn ($query) => $query->where('posted_by', $request->user()->getKey()))
            ->orderByDesc('lesson_on')->orderByDesc('created_at')->paginate(PerPage::from($request, 20));
        $lectures = collect($page->items());
        $files = $this->classroom->query(LectureFile::class, $company)->whereIn('lecture_id', $lectures->pluck('id'))->orderBy('created_at')->get()->groupBy('lecture_id');

        return response()->json([
            'data' => $lectures->map(fn (Lecture $lecture) => $this->presenter->lecture($lecture, $files[$lecture->getKey()] ?? collect(), [
                'may_change' => $this->lectures->mayChange($company, $unit, $request->user(), $lecture),
            ]))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), ...$this->names($company, $lectures)],
        ]);
    }

    public function show(Request $request, string $organization, string $lecture): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_lectures.view', $unit);

        return $this->answer($company, $unit, $request, $this->find($company, $unit, $request, $lecture));
    }

    public function store(LectureRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if (! Gate::allows('education_lectures.post', $unit) && ! Gate::allows('education_lectures.manage', $unit)) {
            Gate::authorize('education_lectures.post', $unit);
        }
        $lecture = $this->lectures->save($company, $unit, null, $request->validated(), $request->user());

        return $this->answer($company, $unit, $request, $lecture, $lecture->wasRecentlyCreated ? 201 : 200);
    }

    public function update(LectureRequest $request, string $organization, string $lecture): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($company, $unit, $request, $lecture);

        return $this->answer($company, $unit, $request, $this->lectures->save($company, $unit, $found, $request->validated(), $request->user()));
    }

    public function publish(Request $request, string $organization, string $lecture): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($company, $unit, $request, $lecture);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1']]);

        return $this->answer($company, $unit, $request, $this->lectures->publish($company, $unit, $found, (int) $data['base_version'], $request->user()));
    }

    public function approve(Request $request, string $organization, string $lecture): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($company, $unit, $request, $lecture);
        Gate::authorize('education_lectures.manage', $unit);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1']]);

        return $this->answer($company, $unit, $request, $this->lectures->approve($company, $unit, $found, (int) $data['base_version'], $request->user()));
    }

    public function withdraw(Request $request, string $organization, string $lecture): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($company, $unit, $request, $lecture);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:300']]);

        return $this->answer($company, $unit, $request, $this->lectures->withdraw($company, $unit, $found, (int) $data['base_version'], trim($data['reason']), $request->user()));
    }

    public function destroy(Request $request, string $organization, string $lecture): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->lectures->deleteDraft($company, $unit, $this->find($company, $unit, $request, $lecture), $request->user());

        return response()->json(['data' => null, 'message' => __('education_lectures::lectures.deleted')]);
    }

    public function addFile(Request $request, string $organization, string $lecture): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($company, $unit, $request, $lecture);
        $data = $request->validate(['file' => ['required', 'file']]);
        $this->lectures->addFile($company, $unit, $found, $data['file'], $request->user());

        return $this->answer($company, $unit, $request, $found->refresh(), 201);
    }

    public function removeFile(Request $request, string $organization, string $lecture, string $file): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($company, $unit, $request, $lecture);
        $item = $this->classroom->query(LectureFile::class, $company)->where('lecture_id', $found->getKey())->whereKey($file)->first()
            ?? throw LectureException::notFound('file');
        $this->lectures->removeFile($company, $unit, $found, $item, $request->user());

        return $this->answer($company, $unit, $request, $found);
    }

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, $this->classroom->companyOf($unit)];
    }

    /** Lectures a person sees at a unit: who manages, all; others, the published ones of their sections and what they posted. */
    private function visible(Organization $company, Organization $unit, Request $request)
    {
        $me = $request->user()->getKey();
        $sections = $this->lectures->visibleSections($company, $unit, $request->user());
        $manages = Gate::allows('education_lectures.manage', $unit);

        return $this->classroom->query(Lecture::class, $company)->whereIn('unit_id', $this->classroom->subtreeIds($unit))
            ->when(! $manages, fn ($query) => $query->where(fn ($query) => $query->where('posted_by', $me)
                ->orWhere(fn ($query) => $query->where('status', 'published')->when($sections !== null, fn ($query) => $query->whereIn('section_id', $sections)))));
    }

    private function find(Organization $company, Organization $unit, Request $request, string $id): Lecture
    {
        Gate::authorize('education_lectures.view', $unit);

        return $this->visible($company, $unit, $request)->whereKey($id)->first() ?? throw LectureException::notFound('lecture');
    }

    private function answer(Organization $company, Organization $unit, Request $request, Lecture $lecture, int $status = 200): JsonResponse
    {
        $files = $this->classroom->query(LectureFile::class, $company)->where('lecture_id', $lecture->getKey())->orderBy('created_at')->get();

        return response()->json(['data' => $this->presenter->lecture($lecture, $files, [
            'may_change' => $this->lectures->mayChange($company, $unit, $request->user(), $lecture),
        ]), 'meta' => $this->names($company, collect([$lecture]))], $status);
    }

    /**
     * Names the screens show: subjects, sections and teachers of these lectures.
     *
     * @param  Collection<int, Lecture>  $lectures
     * @return array<string, mixed>
     */
    private function names(Organization $company, Collection $lectures): array
    {
        $subjects = $this->academic->subjects($company, $lectures->pluck('subject_id')->unique()->values()->all());
        $sections = $lectures->pluck('section_id')->unique()->mapWithKeys(fn (string $id) => [$id => $this->academic->section($company, $id)])->filter();
        $levels = collect($this->academic->levels($company))->keyBy('id');

        return [
            'subjects' => (object) collect($subjects)->map(fn (array $subject) => ['code' => $subject['code'], 'name' => $subject['name']])->all(),
            'sections' => (object) $sections->map(fn (array $section) => ['name' => $section['name'], 'level' => $levels[$section['level_id']]['name'] ?? null])->all(),
            'teachers' => (object) $this->academic->teacherNames($company, $lectures->pluck('teacher_id')->filter()->unique()->values()->all()),
        ];
    }
}
