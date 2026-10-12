<?php

namespace Modules\EducationExams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Directory\ResultDirectory;
use Modules\EducationExams\Exceptions\ExamException;
use Modules\EducationExams\Http\Controllers\Concerns\FindsExamUnit;
use Modules\EducationExams\Http\ExamPresenter;
use Modules\EducationExams\Models\Combination;
use Modules\EducationExams\Models\Correction;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\GradingScheme;
use Modules\EducationExams\Models\MarkSheet;
use Modules\EducationExams\Models\Result;
use Modules\EducationExams\Models\ResultSubject;
use Modules\EducationExams\Services\ExamOffice;
use Modules\EducationExams\Services\Results;

/**
 * Results at the unit and below: worked out (.manage) and published
 * (.publish) per exam; combinations of exams (.manage, published with
 * .publish); corrections of published marks (asked with .correct, decided
 * with .publish by someone else); a student's transcript (.view).
 */
class ResultController extends Controller
{
    use FindsExamUnit;

    public function __construct(private ExamOffice $office, private Results $results, private ExamPresenter $presenter) {}

    /** An exam's results by position (worked out first while it is closed). */
    public function exam(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');

        return response()->json($this->list($company, $unit, 'exam', $found->getKey()));
    }

    public function compute(string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        if ($found->status !== 'closed') {
            throw ExamException::wrongStatus($found->status);
        }
        $this->results->computeExam($company, $found);

        return response()->json($this->list($company, $unit, 'exam', $found->getKey()));
    }

    public function publish(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.publish', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        $saved = $this->results->publishExam($company, $found, $this->version($request), $request->user());

        return response()->json(['data' => $this->presenter->exam($saved)]);
    }

    public function combinations(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $sessionId = $request->validate(['session_id' => ['nullable', 'string', 'size:26']])['session_id'] ?? null;
        $rows = $this->office->query(Combination::class, $company)->whereIn('unit_id', $this->unitIds($unit))->when($sessionId, fn ($query) => $query->where('session_id', $sessionId))
            ->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (Combination $row) => $this->combination($row))->values()]);
    }

    public function storeCombination(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $data = $request->validate($this->combinationRules($company, $unit));
        $saved = $this->results->saveCombination($company, null, [...$data, 'unit_id' => $unit->getKey()], $request->user());

        return response()->json(['data' => $this->combination($saved)], 201);
    }

    public function combinationResults(string $organization, string $combination): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $found = $this->recordIn(Combination::class, $unit, $company, $combination, 'combination');
        if ($found->status === 'draft' && Gate::allows('education_exams.manage', $unit)) {
            $this->results->computeCombination($company, $found);
        }

        return response()->json($this->list($company, $unit, 'combination', $found->getKey()));
    }

    public function publishCombination(Request $request, string $organization, string $combination): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.publish', $unit);
        $found = $this->recordIn(Combination::class, $unit, $company, $combination, 'combination');

        return response()->json(['data' => $this->combination($this->results->publishCombination($company, $found, $this->version($request), $request->user()))]);
    }

    public function corrections(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        $rows = $this->office->query(Correction::class, $company)->where('exam_id', $found->getKey())->whereIn('unit_id', $this->unitIds($unit))->orderByDesc('created_at')->get();
        $students = app(AcademicDirectory::class)->students($company, $rows->pluck('student_id')->unique()->values()->all());

        return response()->json(['data' => $rows->map(fn (Correction $row) => [
            'id' => $row->getKey(),
            ...$row->only(['exam_id', 'sheet_id', 'part_id', 'student_id', 'old_marks_centi', 'old_absent', 'new_marks_centi', 'new_absent', 'reason', 'status', 'requested_by', 'decided_by', 'decision_note', 'version']),
            'student' => array_intersect_key($students[$row->student_id] ?? [], array_flip(['id', 'code', 'name'])),
        ])->values()]);
    }

    public function requestCorrection(Request $request, string $organization, string $sheet): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.correct', $unit);
        $found = $this->recordIn(MarkSheet::class, $unit, $company, $sheet, 'sheet');
        $data = $request->validate([
            'student_id' => ['required', 'string', 'size:26'], 'part_id' => ['required', 'string', 'size:26'],
            'marks_centi' => ['nullable', 'integer', 'min:0', 'max:100000'], 'absent' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $correction = $this->results->requestCorrection($company, $found, [...$data, 'absent' => (bool) ($data['absent'] ?? false), 'marks_centi' => $data['marks_centi'] ?? null], $request->user());

        return response()->json(['data' => $correction->only(['id', 'status', 'new_marks_centi', 'new_absent', 'version'])], 201);
    }

    public function approveCorrection(Request $request, string $organization, string $correction): JsonResponse
    {
        return $this->decide($request, $organization, $correction, true);
    }

    public function rejectCorrection(Request $request, string $organization, string $correction): JsonResponse
    {
        return $this->decide($request, $organization, $correction, false);
    }

    /** A student's published results and cumulative grade point. */
    public function transcript(string $organization, string $student): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $record = app(AcademicDirectory::class)->student($company, $student);
        if ($record === null || ! in_array($record['unit_id'], $this->unitIds($unit), true)) {
            throw ExamException::notFound('student');
        }

        return response()->json(['data' => [
            'student' => array_intersect_key($record, array_flip(['id', 'code', 'name', 'name_local'])),
            ...app(ResultDirectory::class)->transcript($company, $student),
        ]]);
    }

    private function decide(Request $request, string $organization, string $correction, bool $approve): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.publish', $unit);
        $found = $this->recordIn(Correction::class, $unit, $company, $correction, 'correction');
        $data = $request->validate(['note' => [$approve ? 'nullable' : 'required', 'string', 'min:3', 'max:300'], 'base_version' => ['required', 'integer']]);
        $saved = $this->results->decideCorrection($company, $found, $approve, $data['note'] ?? null, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $saved->only(['id', 'status', 'version'])]);
    }

    /** @return array<string, mixed> */
    private function list($company, $unit, string $type, string $sourceId): array
    {
        $rows = $this->office->query(Result::class, $company)->where('source_type', $type)->where('source_id', $sourceId)->whereIn('unit_id', $this->unitIds($unit))->get()
            ->sortBy(fn (Result $row) => [$row->rank ?? PHP_INT_MAX, $row->student_id])->values();
        $subjects = $this->results->subjectsOf($company, $rows->pluck('id')->all())->groupBy('result_id');
        $students = app(AcademicDirectory::class)->students($company, $rows->pluck('student_id')->all());
        $showRank = (bool) app(RuleResolver::class)->get('education_exams.show_rank', app(RuleContextFactory::class)->forOrganization($unit));

        return [
            'data' => $rows->map(fn (Result $row) => [
                ...$row->only(['id', 'student_id', 'section_id', 'level_id', 'marks_centi', 'full_marks_centi', 'percent_bp', 'gpa_centi', 'letter', 'passed', 'failed_subjects', 'status']),
                'rank' => $showRank ? $row->rank : null, 'section_rank' => $showRank ? $row->section_rank : null,
                'student' => array_intersect_key($students[$row->student_id] ?? [], array_flip(['id', 'code', 'name', 'name_local'])),
                'subjects' => collect($subjects[$row->getKey()] ?? [])->map(fn (ResultSubject $subject) => $subject->only(['subject_id', 'kind', 'marks_centi', 'full_marks_centi', 'percent_bp', 'letter', 'point_centi', 'passed', 'absent']))->values(),
            ]),
            'meta' => [
                'students' => $rows->count(), 'passed' => $rows->where('passed', true)->count(), 'show_rank' => $showRank,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function combination(Combination $row): array
    {
        return ['id' => $row->getKey(), ...$row->only(['unit_id', 'session_id', 'level_id', 'exams', 'grading_scheme_id', 'status', 'version']), 'name' => $row->texts('name')];
    }

    /** @return array<string, mixed> */
    private function combinationRules($company, $unit): array
    {
        return [
            'name' => ['required', 'array:'.implode(',', LanguageRegistry::codes())],
            'name.*' => ['nullable', 'string', 'max:120'],
            'session_id' => ['required', Rule::in(array_column(app(AcademicDirectory::class)->sessions($company), 'id'))],
            'level_id' => ['required', Rule::in(array_column(app(AcademicDirectory::class)->levels($company), 'id'))],
            'grading_scheme_id' => ['required', Rule::in($this->office->query(GradingScheme::class, $company)->where('is_active', true)->pluck('id')->all())],
            'exams' => ['required', 'array', 'min:1', 'max:10'],
            'exams.*' => ['array:exam_id,weight_bp'],
            'exams.*.exam_id' => ['required', 'distinct', Rule::in($this->office->query(Exam::class, $company)->whereIn('unit_id', $this->unitIds($unit))->pluck('id')->all())],
            'exams.*.weight_bp' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['base_version' => ['required', 'integer']])['base_version'];
    }
}
