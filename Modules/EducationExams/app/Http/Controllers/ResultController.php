<?php

namespace Modules\EducationExams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
use Modules\EducationExams\Models\Paper;
use Modules\EducationExams\Models\PaperPart;
use Modules\EducationExams\Models\Result;
use Modules\EducationExams\Models\ResultSubject;
use Modules\EducationExams\Services\ExamOffice;
use Modules\EducationExams\Services\ResultAccess;
use Modules\EducationExams\Services\Results;

/**
 * Results at the unit and below: worked out (.manage) and published
 * (.publish) per exam; combinations of exams (.manage, published with
 * .publish); corrections of published marks (asked with .correct, decided
 * with .publish by someone else); a student's transcript (.view). Whose
 * marks a reader sees: ResultAccess (teachers their sections, by rule).
 */
class ResultController extends Controller
{
    use FindsExamUnit;

    public function __construct(private ExamOffice $office, private Results $results, private ExamPresenter $presenter, private ResultAccess $access) {}

    /** An exam's results by position (worked out first while it is closed). */
    public function exam(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $found = $this->examIn($unit, $company, $exam);

        return response()->json($this->list($company, $unit, $request->user(), 'exam', $found->getKey()));
    }

    public function compute(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.manage', $unit);
        $found = $this->recordIn(Exam::class, $unit, $company, $exam, 'exam');
        if ($found->status !== 'closed') {
            throw ExamException::wrongStatus($found->status);
        }
        $this->results->computeExam($company, $found);

        return response()->json($this->list($company, $unit, $request->user(), 'exam', $found->getKey()));
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

    public function combinationResults(Request $request, string $organization, string $combination): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $found = $this->recordIn(Combination::class, $unit, $company, $combination, 'combination');
        if ($found->status === 'draft' && Gate::allows('education_exams.manage', $unit)) {
            $this->results->computeCombination($company, $found);
        }

        return response()->json($this->list($company, $unit, $request->user(), 'combination', $found->getKey()));
    }

    public function publishCombination(Request $request, string $organization, string $combination): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.publish', $unit);
        $found = $this->recordIn(Combination::class, $unit, $company, $combination, 'combination');

        return response()->json(['data' => $this->combination($this->results->publishCombination($company, $found, $this->version($request), $request->user()))]);
    }

    /** An exam's corrections, newest first. */
    public function corrections(Request $request, string $organization, string $exam): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $found = $this->examIn($unit, $company, $exam);

        return response()->json($this->correctionList($company, $unit, $request->user(),
            $this->office->query(Correction::class, $company)->where('exam_id', $found->getKey())->whereIn('unit_id', $this->unitIds($unit))->orderByDesc('created_at')->get()));
    }

    /** Corrections across the exams here (newest first), by status: what waits for approval, with what each is about. */
    public function allCorrections(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $status = $request->validate(['status' => ['nullable', 'in:pending,applied,rejected']])['status'] ?? null;

        return response()->json($this->correctionList($company, $unit, $request->user(),
            $this->office->query(Correction::class, $company)->whereIn('unit_id', $this->unitIds($unit))
                ->when($status, fn ($query) => $query->where('status', $status))->orderByDesc('created_at')->limit(300)->get()));
    }

    /**
     * Corrections the reader sees, each with its exam, subject, part and
     * student named, and the names of who asked and decided.
     *
     * @param  Collection<int, Correction>  $rows
     * @return array<string, mixed>
     */
    private function correctionList($company, $unit, User $user, Collection $rows): array
    {
        $rows = $this->visibleCorrections($company, $unit, $user, $rows);
        $academic = app(AcademicDirectory::class);
        $students = $academic->students($company, $rows->pluck('student_id')->unique()->values()->all());
        $exams = $this->office->query(Exam::class, $company)->whereKey($rows->pluck('exam_id')->unique()->values())->get()->keyBy('id');
        $sheets = $this->office->query(MarkSheet::class, $company)->whereKey($rows->pluck('sheet_id')->unique()->values())->get(['id', 'paper_id', 'section_id'])->keyBy('id');
        $papers = $this->office->query(Paper::class, $company)->whereKey($sheets->pluck('paper_id')->unique()->values())->get(['id', 'subject_id', 'level_id'])->keyBy('id');
        $subjects = $academic->subjects($company, $papers->pluck('subject_id')->unique()->values()->all());
        $parts = $this->office->query(PaperPart::class, $company)->whereKey($rows->pluck('part_id')->unique()->values())->get()->keyBy('id');

        return [
            'data' => $rows->map(function (Correction $row) use ($students, $exams, $sheets, $papers, $subjects, $parts) {
                $paper = $papers[$sheets[$row->sheet_id]->paper_id ?? ''] ?? null;
                $part = $parts[$row->part_id] ?? null;

                return [
                    'id' => $row->getKey(),
                    ...$row->only(['exam_id', 'sheet_id', 'part_id', 'student_id', 'old_marks_centi', 'old_absent', 'new_marks_centi', 'new_absent', 'reason', 'status', 'requested_by', 'decided_by', 'decision_note', 'version']),
                    'created_at' => $row->created_at?->toIso8601String(),
                    'student' => array_intersect_key($students[$row->student_id] ?? [], array_flip(['id', 'code', 'name'])),
                    'exam' => isset($exams[$row->exam_id]) ? ['id' => $row->exam_id, 'name' => $exams[$row->exam_id]->texts('name')] : null,
                    'subject' => $paper === null ? null : array_intersect_key($subjects[$paper->subject_id] ?? [], array_flip(['id', 'code', 'name'])),
                    'level_id' => $paper?->level_id,
                    'part' => $part === null ? null : ['id' => $part->getKey(), 'code' => $part->code, 'name' => $part->texts('name'), 'full_marks_centi' => $part->full_marks_centi],
                ];
            })->values(),
            'people' => $this->people($rows->pluck('requested_by')->merge($rows->pluck('decided_by'))),
        ];
    }

    /**
     * The names of the people a list mentions (who asked, who decided); names only.
     *
     * @param  iterable<string|null>  $ids
     * @return array<string, string>
     */
    private function people(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values()->all();

        return $ids === [] ? [] : User::query()->whereKey($ids)->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
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

    /** Students here whose whole result the reader may see, by name, ID or phone (to open a transcript). */
    public function students(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $term = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q'];
        $academic = app(AcademicDirectory::class);
        $whole = $this->access->wholeSections($this->access->scope($company, $unit, $request->user()));
        $found = collect($academic->search($company, $term, $this->unitIds($unit), 20))
            ->filter(fn (array $student) => $academic->studentInSections($company, $whole, $student['id']));

        return response()->json(['data' => $found->map(fn (array $student) => array_intersect_key($student, array_flip(['id', 'code', 'name', 'name_local'])))->values()]);
    }

    /** A student's published results and cumulative grade point. */
    public function transcript(Request $request, string $organization, string $student): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_exams.view', $unit);
        $record = app(AcademicDirectory::class)->student($company, $student);
        $whole = $this->access->wholeSections($this->access->scope($company, $unit, $request->user()));
        if ($record === null || ! in_array($record['unit_id'], $this->unitIds($unit), true) || ! app(AcademicDirectory::class)->studentInSections($company, $whole, $student)) {
            throw ExamException::notFound('student');
        }

        $transcript = app(ResultDirectory::class)->transcript($company, $student);
        $showRank = (bool) app(RuleResolver::class)->get('education_exams.show_rank', app(RuleContextFactory::class)->forOrganization($unit));
        if (! $showRank) {
            $transcript['results'] = array_map(fn (array $row) => [...$row, 'rank' => null, 'section_rank' => null], $transcript['results']);
        }

        return response()->json(['data' => [
            'student' => array_intersect_key($record, array_flip(['id', 'code', 'name', 'name_local'])),
            ...$transcript,
            'show_rank' => $showRank,
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

    /**
     * Results of an exam or a combination by position, as far as the reader
     * may see them: a section they see whole as it is; a section where they
     * see some subjects only those subjects (no total, GPA or position).
     *
     * @return array<string, mixed>
     */
    private function list($company, $unit, User $user, string $type, string $sourceId): array
    {
        $scope = $this->access->scope($company, $unit, $user);
        $rows = $this->office->query(Result::class, $company)->where('source_type', $type)->where('source_id', $sourceId)->whereIn('unit_id', $this->unitIds($unit))->get()
            ->filter(fn (Result $row) => $scope === null || isset($scope[$row->section_id ?? '']))
            ->sortBy(fn (Result $row) => [$row->rank ?? PHP_INT_MAX, $row->student_id])->values();
        $subjects = $this->results->subjectsOf($company, $rows->pluck('id')->all())->groupBy('result_id');
        $students = app(AcademicDirectory::class)->students($company, $rows->pluck('student_id')->all());
        $showRank = (bool) app(RuleResolver::class)->get('education_exams.show_rank', app(RuleContextFactory::class)->forOrganization($unit));
        $overall = ['marks_centi', 'full_marks_centi', 'percent_bp', 'gpa_centi', 'letter', 'passed', 'failed_subjects'];
        $data = $rows->map(function (Result $row) use ($scope, $subjects, $students, $showRank, $overall) {
            $whole = $this->access->seesWhole($scope, $row->section_id);

            return [
                ...$row->only(['id', 'student_id', 'section_id', 'level_id', 'status']),
                ...array_map(fn ($value) => $whole ? $value : null, $row->only($overall)),
                'partial' => ! $whole,
                'rank' => $showRank && $whole ? $row->rank : null, 'section_rank' => $showRank && $whole ? $row->section_rank : null,
                'student' => array_intersect_key($students[$row->student_id] ?? [], array_flip(['id', 'code', 'name', 'name_local'])),
                'subjects' => collect($subjects[$row->getKey()] ?? [])
                    ->filter(fn (ResultSubject $subject) => $this->access->seesSubject($scope, $row->section_id, $subject->subject_id))
                    ->map(fn (ResultSubject $subject) => $subject->only(['subject_id', 'kind', 'marks_centi', 'full_marks_centi', 'percent_bp', 'letter', 'point_centi', 'passed', 'absent']))->values(),
            ];
        });
        $wholeRows = $data->where('partial', false);

        return [
            'data' => $data->values(),
            'meta' => [
                'students' => $data->count(), 'passed' => $wholeRows->where('passed', true)->count(), 'show_rank' => $showRank,
                'partial' => $data->contains('partial', true),
                // Section names by id, for filters and rows.
                'sections' => $rows->pluck('section_id')->filter()->unique()->mapWithKeys(fn (string $id) => [$id => app(AcademicDirectory::class)->section($company, $id)['name'] ?? ''])->all(),
            ],
        ];
    }

    /**
     * Corrections of sections and subjects the reader sees.
     *
     * @param  Collection<int, Correction>  $rows
     * @return Collection<int, Correction>
     */
    private function visibleCorrections($company, $unit, User $user, Collection $rows): Collection
    {
        $scope = $this->access->scope($company, $unit, $user);
        if ($scope === null) {
            return $rows;
        }
        $sheets = $this->office->query(MarkSheet::class, $company)->whereKey($rows->pluck('sheet_id')->unique()->values())->get(['id', 'section_id', 'paper_id'])->keyBy('id');
        $subjects = $this->office->query(Paper::class, $company)->whereKey($sheets->pluck('paper_id')->unique()->values())->pluck('subject_id', 'id');

        return $rows->filter(function (Correction $row) use ($scope, $sheets, $subjects) {
            $sheet = $sheets[$row->sheet_id] ?? null;

            return $sheet !== null && $this->access->seesSubject($scope, $sheet->section_id, (string) ($subjects[$sheet->paper_id] ?? ''));
        })->values();
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
