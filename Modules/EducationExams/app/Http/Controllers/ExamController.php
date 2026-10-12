<?php

namespace Modules\EducationExams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Exceptions\ExamException;
use Modules\EducationExams\Http\Controllers\Concerns\FindsExamUnit;
use Modules\EducationExams\Http\ExamPresenter;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\GradingScheme;
use Modules\EducationExams\Models\MarkSheet;
use Modules\EducationExams\Models\Paper;
use Modules\EducationExams\Services\ExamOffice;
use Modules\EducationExams\Services\Exams;
use Modules\EducationExams\Services\Grading;
use Modules\EducationExams\Services\MarkSheets;

/**
 * Exams at the unit in the address and below: grading schemes, exams and
 * their papers (read with education_exams.view, changed with .manage),
 * and what the screens start with.
 */
class ExamController extends Controller
{
    use FindsExamUnit;

    private const CAN = ['manage', 'enter_marks', 'moderate', 'publish', 'correct'];

    public function __construct(private ExamOffice $office, private Exams $exams, private Grading $grading, private ExamPresenter $presenter) {}

    public function setup(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $academic = app(AcademicDirectory::class);
        $schemes = $this->grading->schemes($company);
        $bands = $this->grading->bands($company, $schemes->pluck('id')->all());
        $context = app(RuleContextFactory::class)->forOrganization($unit);
        $rule = fn (string $key) => app(RuleResolver::class)->get("education_exams.{$key}", $context);
        $manage = Gate::allows('education_exams.manage', $unit);

        return response()->json(['data' => [
            'schemes' => $schemes->map(fn (GradingScheme $scheme) => $this->presenter->scheme($scheme, $bands[$scheme->getKey()] ?? []))->values(),
            'sessions' => $academic->sessions($company),
            'levels' => $academic->levels($company),
            'subjects' => $academic->allSubjects($company),
            'campuses' => Organization::query()->whereKey($this->unitIds($unit))->whereNot('type', OrganizationType::Department->value)->orderBy('depth')->get()
                ->map(fn (Organization $campus) => ['id' => $campus->getKey(), 'name' => $campus->displayName()])->values(),
            'rules' => [
                'default_grading_scheme' => $rule('default_grading_scheme'),
                'moderation_required' => (bool) $rule('moderation_required'),
                'show_rank' => (bool) $rule('show_rank'),
                'gpa_by_credits' => (bool) $rule('gpa_by_credits'),
                'correction_needs_approval' => (bool) $rule('correction_needs_approval'),
            ],
            // Who a sheet can be given to (people who manage exams only); without HRM, the class teacher enters.
            'hrm' => $academic->teachersAvailable($company),
            'teachers' => $manage ? $academic->teachers($company, $this->unitIds($unit)) : [],
            'today' => $this->office->today($company)->toDateString(),
            'can' => array_combine(self::CAN, array_map(fn (string $can) => Gate::allows("education_exams.{$can}", $unit), self::CAN)),
        ]]);
    }

    public function storeScheme(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $scheme = $this->grading->save($company, null, $request->validate($this->schemeRules($company, null)), $request->user());

        return response()->json(['data' => $this->presenter->scheme($scheme, $this->grading->bands($company, [$scheme->getKey()])[$scheme->getKey()] ?? [])], 201);
    }

    public function updateScheme(Request $request, string $organization, string $scheme): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $found = $this->office->find(GradingScheme::class, $company, $scheme, 'scheme');
        $data = $request->validate([...$this->schemeRules($company, $found), 'base_version' => ['required', 'integer']]);
        $saved = $this->grading->save($company, $found, $data, $request->user());

        return response()->json(['data' => $this->presenter->scheme($saved, $this->grading->bands($company, [$saved->getKey()])[$saved->getKey()] ?? [])]);
    }

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $sessionId = $request->validate(['session_id' => ['nullable', 'string', 'size:26']])['session_id'] ?? null;
        $exams = $this->office->query(Exam::class, $company)->whereIn('unit_id', $this->examUnitIds($unit))
            ->when($sessionId, fn ($query) => $query->where('session_id', $sessionId))->orderByDesc('starts_on')->orderByDesc('created_at')->limit(200)->get();
        $sheets = $this->office->query(MarkSheet::class, $company)->whereIn('exam_id', $exams->pluck('id'))->whereIn('unit_id', $this->unitIds($unit))->get(['exam_id', 'status'])->groupBy('exam_id');

        return response()->json(['data' => $exams->map(fn (Exam $exam) => $this->presenter->exam($exam, collect($sheets[$exam->getKey()] ?? [])->countBy('status')->all()))->values()]);
    }

    public function show(string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $found = $this->examIn($unit, $company, $exam);

        return response()->json(['data' => $this->examView($unit, $company, $found)]);
    }

    public function store(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $data = $request->validate($this->examRules($company, $unit, true));
        $exam = $this->exams->save($company, null, [...$data, 'unit_id' => $data['unit_id'] ?? $unit->getKey()], $request->user());

        return response()->json(['data' => $this->examView($unit, $company, $exam)], 201);
    }

    public function update(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        $data = $request->validate([...$this->examRules($company, $unit, false), 'base_version' => ['required', 'integer']]);

        return response()->json(['data' => $this->examView($unit, $company, $this->exams->save($company, $found, $data, $request->user()))]);
    }

    public function status(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        $data = $request->validate(['status' => ['required', 'in:marking,closed'], 'base_version' => ['required', 'integer']]);
        $saved = $this->exams->setStatus($company, $found, $data['status'], (int) $data['base_version'], $request->user(), app(MarkSheets::class));

        return response()->json(['data' => $this->examView($unit, $company, $saved)]);
    }

    public function storePaper(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        $paper = $this->exams->savePaper($company, $found, null, $request->validate($this->paperRules($company, $found, true)), $request->user());

        return response()->json(['data' => $this->paperView($company, $paper)], 201);
    }

    public function updatePaper(Request $request, string $organization, string $exam, string $paper): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        $item = $this->office->query(Paper::class, $company)->whereKey($paper)->where('exam_id', $found->getKey())->first() ?? throw ExamException::notFound('paper');
        $data = $request->validate([...$this->paperRules($company, $found, false), 'base_version' => ['required', 'integer']]);

        return response()->json(['data' => $this->paperView($company, $this->exams->savePaper($company, $found, $item, $data, $request->user()))]);
    }

    public function deletePaper(Request $request, string $organization, string $exam, string $paper): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        $item = $this->office->query(Paper::class, $company)->whereKey($paper)->where('exam_id', $found->getKey())->first() ?? throw ExamException::notFound('paper');
        $this->exams->deletePaper($company, $item, $request->user());

        return response()->json(null, 204);
    }

    public function fromCurriculum(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        $data = $request->validate([
            'level_id' => ['required', Rule::in(array_column(app(AcademicDirectory::class)->levels($company), 'id'))],
            'full_marks_centi' => ['required', 'integer', 'min:100', 'max:100000'],
            'pass_marks_centi' => ['required', 'integer', 'min:0', 'lte:full_marks_centi'],
        ]);
        $made = $this->exams->papersFromCurriculum($company, $found, $data['level_id'], (int) $data['full_marks_centi'], (int) $data['pass_marks_centi'], $request->user());

        return response()->json(['data' => $made->map(fn (Paper $paper) => $this->paperView($company, $paper))->values()], 201);
    }

    /** @return array<string, mixed> The exam with its papers; its sheets counted at the unit and below. */
    private function examView(Organization $unit, $company, Exam $exam): array
    {
        $papers = $this->office->query(Paper::class, $company)->where('exam_id', $exam->getKey())->orderBy('level_id')->orderBy('sits_on')->get();
        $parts = $this->exams->parts($company, $papers->pluck('id')->all())->groupBy('paper_id');
        $subjects = app(AcademicDirectory::class)->subjects($company, $papers->pluck('subject_id')->unique()->values()->all());
        $sheets = $this->office->query(MarkSheet::class, $company)->where('exam_id', $exam->getKey())->whereIn('unit_id', $this->unitIds($unit))->get(['status'])->countBy('status')->all();

        return [
            ...$this->presenter->exam($exam, $sheets),
            'papers' => $papers->map(fn (Paper $paper) => $this->presenter->paper($paper, collect($parts[$paper->getKey()] ?? []), $subjects[$paper->subject_id] ?? null))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function paperView($company, Paper $paper): array
    {
        $subject = app(AcademicDirectory::class)->subjects($company, [$paper->subject_id])[$paper->subject_id] ?? null;

        return $this->presenter->paper($paper, $this->exams->parts($company, [$paper->getKey()]), $subject);
    }

    /** @return array<string, mixed> */
    private function schemeRules($company, ?GradingScheme $scheme): array
    {
        $required = $scheme === null ? 'required' : 'sometimes';
        $taken = $this->office->query(GradingScheme::class, $company)->when($scheme, fn ($query) => $query->whereKeyNot($scheme->getKey()))->pluck('code')->all();

        return [
            'code' => [$required, 'string', 'min:2', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::notIn($taken)],
            'name' => [$required, 'array:'.implode(',', LanguageRegistry::codes())],
            'name.*' => ['nullable', 'string', 'max:120'],
            'max_point_centi' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'bands' => [$required, 'array', 'min:1', 'max:20'],
            'bands.*' => ['array:min_percent_bp,letter,point_centi,is_pass'],
            'bands.*.min_percent_bp' => ['required', 'integer', 'min:0', 'max:10000', 'distinct'],
            'bands.*.letter' => ['required', 'string', 'max:10'],
            'bands.*.point_centi' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'bands.*.is_pass' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function examRules($company, Organization $unit, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'array:'.implode(',', LanguageRegistry::codes())],
            'name.*' => ['nullable', 'string', 'max:120'],
            'session_id' => [$creating ? 'required' : 'prohibited', Rule::in(array_column(app(AcademicDirectory::class)->sessions($company), 'id'))],
            'unit_id' => [$creating ? 'nullable' : 'prohibited', Rule::in($this->unitIds($unit))],
            'kind' => [$required, Rule::in(Exam::KINDS)],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'grading_scheme_id' => [$required, Rule::in($this->office->query(GradingScheme::class, $company)->where('is_active', true)->pluck('id')->all())],
        ];
    }

    /** @return array<string, mixed> */
    private function paperRules($company, Exam $exam, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'level_id' => [$creating ? 'required' : 'prohibited', Rule::in(array_column(app(AcademicDirectory::class)->levels($company), 'id'))],
            'subject_id' => [$creating ? 'required' : 'prohibited', Rule::in(array_column(app(AcademicDirectory::class)->allSubjects($company), 'id'))],
            'kind' => ['sometimes', 'in:compulsory,elective,optional'],
            'full_marks_centi' => [$required, 'integer', 'min:100', 'max:100000'],
            'pass_marks_centi' => ['sometimes', 'integer', 'min:0', 'lte:full_marks_centi'],
            'sits_on' => ['nullable', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'room' => ['nullable', 'string', 'max:40'],
            'parts' => ['sometimes', 'array', 'min:1', 'max:6'],
            'parts.*' => ['array:code,name,full_marks_centi,pass_marks_centi'],
            'parts.*.code' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'parts.*.name' => ['nullable', 'array:'.implode(',', LanguageRegistry::codes())],
            'parts.*.full_marks_centi' => ['required', 'integer', 'min:1'],
            'parts.*.pass_marks_centi' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
