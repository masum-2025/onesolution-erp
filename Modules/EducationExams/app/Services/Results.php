<?php

namespace Modules\EducationExams\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Events\MarksCorrected;
use Modules\EducationExams\Events\ResultPublished;
use Modules\EducationExams\Exceptions\ExamException;
use Modules\EducationExams\Models\Combination;
use Modules\EducationExams\Models\Correction;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\GradingScheme;
use Modules\EducationExams\Models\Mark;
use Modules\EducationExams\Models\MarkSheet;
use Modules\EducationExams\Models\Paper;
use Modules\EducationExams\Models\PaperPart;
use Modules\EducationExams\Models\Result;
use Modules\EducationExams\Models\ResultSubject;

/**
 * Results.
 *
 * - An exam's results: each student's marks per subject (parts and pass
 *   marks), the band, then the overall grade point (by subject, or by
 *   credits when rule education_exams.gpa_by_credits), the optional
 *   subject's bonus above rule education_exams.optional_bonus_above, fail
 *   when any counted subject fails (rule education_exams.fail_any_subject),
 *   and merit positions in the class and the section. Computed as often as
 *   needed while closed; published when every sheet is approved.
 * - A combination weighs several exams of a session for a class (term 30 %
 *   + final 70 %): each subject's weighted percentage, then the same grading.
 * - After publishing, a mark changes only by a correction with a reason,
 *   applied by someone else when rule education_exams.correction_needs_approval;
 *   the exam's (and its combinations') results are worked out again.
 */
class Results
{
    public function __construct(
        private ExamOffice $office,
        private Grading $grading,
        private AcademicDirectory $academic,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** Works out (again) an exam's results; published ones stay published. */
    public function computeExam(Organization $company, Exam $exam): int
    {
        $papers = $this->office->query(Paper::class, $company)->where('exam_id', $exam->getKey())->get()->keyBy('id');
        $parts = $this->office->query(PaperPart::class, $company)->whereIn('paper_id', $papers->keys())->orderBy('sort_order')->get()->groupBy('paper_id');
        $sheets = $this->office->query(MarkSheet::class, $company)->where('exam_id', $exam->getKey())->get();
        $marks = $this->office->query(Mark::class, $company)->whereIn('sheet_id', $sheets->pluck('id'))->get()
            ->groupBy(fn (Mark $mark) => "{$mark->student_id}:{$mark->paper_id}");
        $bands = $this->grading->bands($company, [$exam->grading_scheme_id])[$exam->grading_scheme_id] ?? [];
        $credits = collect($this->academic->subjects($company, $papers->pluck('subject_id')->unique()->values()->all()))->map(fn (array $subject) => (int) ($subject['credits_centi'] ?? 0));

        $students = [];
        foreach ($sheets as $sheet) {
            $paper = $papers[$sheet->paper_id] ?? null;
            if ($paper === null) {
                continue;
            }
            foreach ($this->academic->sectionStudents($company, $sheet->section_id) as $student) {
                $students[$student['id']] ??= ['unit_id' => $sheet->unit_id, 'level_id' => $paper->level_id, 'section_id' => $sheet->section_id, 'subjects' => []];
                $mine = collect($marks["{$student['id']}:{$paper->getKey()}"] ?? [])->keyBy('part_id');
                $students[$student['id']]['subjects'][] = [
                    'subject_id' => $paper->subject_id, 'paper_id' => $paper->getKey(), 'kind' => $paper->kind, 'credits_centi' => $credits[$paper->subject_id] ?? 0,
                    ...ExamMath::subject(collect($parts[$paper->getKey()] ?? [])->map(fn (PaperPart $part) => [
                        'full_marks_centi' => $part->full_marks_centi, 'pass_marks_centi' => $part->pass_marks_centi,
                        'marks_centi' => $mine[$part->getKey()]->marks_centi ?? null, 'absent' => (bool) ($mine[$part->getKey()]->absent ?? ! isset($mine[$part->getKey()])),
                    ])->values()->all(), $paper->pass_marks_centi, $bands),
                ];
            }
        }

        return $this->store($company, 'exam', $exam->getKey(), $exam->session_id, $exam->grading_scheme_id, $students);
    }

    /** Publishes an exam's results: every sheet approved; the exam is published. */
    public function publishExam(Organization $company, Exam $exam, int $baseVersion, User $actor): Exam
    {
        return $this->office->transaction($company, function () use ($company, $exam, $baseVersion, $actor) {
            /** @var Exam $exam */
            $exam = $this->office->query(Exam::class, $company)->whereKey($exam->getKey())->lockForUpdate()->firstOrFail();
            if ($exam->version !== $baseVersion) {
                throw ExamException::versionConflict(['version' => $exam->version]);
            }
            if ($exam->status !== 'closed') {
                throw ExamException::wrongStatus($exam->status);
            }
            $waiting = $this->office->query(MarkSheet::class, $company)->where('exam_id', $exam->getKey())->where('status', '!=', 'approved')->count();
            if ($waiting > 0) {
                throw ExamException::sheetsNotApproved($waiting);
            }
            $this->computeExam($company, $exam);
            $this->office->query(Result::class, $company)->where('source_type', 'exam')->where('source_id', $exam->getKey())->update(['status' => 'published', 'published_at' => now()]);
            $exam->forceFill(['status' => 'published', 'version' => $exam->version + 1])->save();
            $this->audit->record('education_exams.exam_published', $exam, old: ['status' => 'closed'], new: ['status' => 'published'], actor: $actor, organizationId: $company->getKey());
            DB::afterCommit(fn () => event(new ResultPublished($company->getKey(), 'exam', $exam->getKey(), $exam->session_id)));

            return $exam;
        });
    }

    /** @param  array<string, mixed>  $data  name, level_id, session_id, unit_id, grading_scheme_id, exams: [{exam_id, weight_bp}] */
    public function saveCombination(Organization $company, ?Combination $combination, array $data, User $actor): Combination
    {
        if (isset($data['exams']) && array_sum(array_column($data['exams'], 'weight_bp')) !== 10000) {
            throw ExamException::weightsNotWhole();
        }

        return $this->office->transaction($company, function () use ($company, $combination, $data, $actor) {
            if ($combination !== null && $combination->status === 'published') {
                throw ExamException::wrongStatus($combination->status);
            }
            $combination ??= new Combination(['organization_id' => $company->getKey(), 'status' => 'draft', 'created_by' => $actor->getKey(), 'version' => 0]);
            $combination->fill(collect($data)->only(['unit_id', 'session_id', 'level_id', 'grading_scheme_id', 'exams'])->all());
            if (isset($data['name'])) {
                $combination->putTexts('name', $data['name']);
            }
            $combination->version++;
            $combination->save();
            $this->audit->record('education_exams.combination_saved', $combination, new: $combination->only(['session_id', 'level_id', 'exams']), actor: $actor, organizationId: $company->getKey());

            return $combination;
        });
    }

    /** Works out a combination's results from its exams' results: each subject's weighted percentage. */
    public function computeCombination(Organization $company, Combination $combination): int
    {
        $weights = collect($combination->exams)->pluck('weight_bp', 'exam_id');
        $results = $this->office->query(Result::class, $company)->where('source_type', 'exam')->whereIn('source_id', $weights->keys())
            ->where('level_id', $combination->level_id)->get();
        $subjects = $this->office->query(ResultSubject::class, $company)->whereIn('result_id', $results->pluck('id'))->get()->groupBy('result_id');
        $bands = $this->grading->bands($company, [$combination->grading_scheme_id])[$combination->grading_scheme_id] ?? [];

        $students = [];
        foreach ($results->groupBy('student_id') as $studentId => $rows) {
            $latest = $rows->sortByDesc('updated_at')->first();
            $bySubject = [];
            foreach ($rows as $row) {
                foreach ($subjects[$row->getKey()] ?? [] as $subject) {
                    $bySubject[$subject->subject_id][] = ['subject' => $subject, 'weight_bp' => (int) ($weights[$row->source_id] ?? 0)];
                }
            }
            $students[$studentId] = ['unit_id' => $latest->unit_id, 'level_id' => $latest->level_id, 'section_id' => $latest->section_id, 'subjects' => []];
            foreach ($bySubject as $subjectId => $entries) {
                $percent = ExamMath::weightedPercent(array_map(fn (array $entry) => ['percent_bp' => $entry['subject']->percent_bp, 'weight_bp' => $entry['weight_bp']], $entries));
                $band = ExamMath::band($bands, $percent);
                $passed = (bool) ($band['is_pass'] ?? false);
                $first = $entries[0]['subject'];
                $students[$studentId]['subjects'][] = [
                    'subject_id' => $subjectId, 'paper_id' => null, 'kind' => $first->kind, 'credits_centi' => $first->credits_centi,
                    'marks_centi' => (int) collect($entries)->sum(fn (array $entry) => $entry['subject']->marks_centi), 'full_marks_centi' => (int) collect($entries)->sum(fn (array $entry) => $entry['subject']->full_marks_centi),
                    'percent_bp' => $percent, 'letter' => $passed ? ($band['letter'] ?? null) : (ExamMath::band($bands, 0)['letter'] ?? null),
                    'point_centi' => $passed ? (int) ($band['point_centi'] ?? 0) : 0, 'passed' => $passed, 'absent' => false,
                ];
            }
        }

        return $this->store($company, 'combination', $combination->getKey(), $combination->session_id, $combination->grading_scheme_id, $students);
    }

    public function publishCombination(Organization $company, Combination $combination, int $baseVersion, User $actor): Combination
    {
        return $this->office->transaction($company, function () use ($company, $combination, $baseVersion, $actor) {
            /** @var Combination $combination */
            $combination = $this->office->query(Combination::class, $company)->whereKey($combination->getKey())->lockForUpdate()->firstOrFail();
            if ($combination->version !== $baseVersion) {
                throw ExamException::versionConflict(['version' => $combination->version]);
            }
            if ($combination->status !== 'draft') {
                throw ExamException::wrongStatus($combination->status);
            }
            $unpublished = $this->office->query(Exam::class, $company)->whereKey(collect($combination->exams)->pluck('exam_id'))->where('status', '!=', 'published')->count();
            if ($unpublished > 0) {
                throw ExamException::examsNotPublished($unpublished);
            }
            $this->computeCombination($company, $combination);
            $this->office->query(Result::class, $company)->where('source_type', 'combination')->where('source_id', $combination->getKey())->update(['status' => 'published', 'published_at' => now()]);
            $combination->forceFill(['status' => 'published', 'version' => $combination->version + 1])->save();
            $this->audit->record('education_exams.combination_published', $combination, new: ['status' => 'published'], actor: $actor, organizationId: $company->getKey());
            DB::afterCommit(fn () => event(new ResultPublished($company->getKey(), 'combination', $combination->getKey(), $combination->session_id)));

            return $combination;
        });
    }

    /**
     * Asks to change a published mark (applied at once when the rule needs no second person).
     *
     * @param  array{student_id: string, part_id: string, marks_centi: ?int, absent: bool, reason: string}  $data
     */
    public function requestCorrection(Organization $company, MarkSheet $sheet, array $data, User $actor): Correction
    {
        return $this->office->transaction($company, function () use ($company, $sheet, $data, $actor) {
            $exam = $this->office->query(Exam::class, $company)->findOrFail($sheet->exam_id);
            if ($exam->status !== 'published') {
                throw ExamException::wrongStatus($exam->status);
            }
            $part = $this->office->query(PaperPart::class, $company)->where('paper_id', $sheet->paper_id)->whereKey($data['part_id'])->first() ?? throw ExamException::notFound('part');
            if (! in_array($data['student_id'], array_column($this->academic->sectionStudents($company, $sheet->section_id), 'id'), true)) {
                throw ExamException::studentNotInSection();
            }
            if (! $data['absent'] && $data['marks_centi'] !== null && $data['marks_centi'] > $part->full_marks_centi) {
                throw ExamException::marksTooHigh($part->code, ExamMath::centiText($part->full_marks_centi));
            }
            $mark = $this->office->query(Mark::class, $company)->where('sheet_id', $sheet->getKey())->where('student_id', $data['student_id'])->where('part_id', $part->getKey())->first();
            $correction = new Correction;
            $correction->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $sheet->unit_id, 'exam_id' => $exam->getKey(), 'sheet_id' => $sheet->getKey(), 'part_id' => $part->getKey(),
                'student_id' => $data['student_id'], 'old_marks_centi' => $mark?->marks_centi, 'old_absent' => (bool) $mark?->absent,
                'new_marks_centi' => $data['absent'] ? null : $data['marks_centi'], 'new_absent' => $data['absent'], 'reason' => $data['reason'],
                'status' => 'pending', 'requested_by' => $actor->getKey(), 'version' => 1,
            ]);
            $correction->save();
            $this->audit->record('education_exams.correction_requested', $correction, new: $correction->only(['student_id', 'part_id', 'old_marks_centi', 'new_marks_centi', 'new_absent']), reason: $correction->reason, actor: $actor, organizationId: $company->getKey());
            $unit = Organization::query()->find($sheet->unit_id) ?? $company;
            if (! $this->rules->get('education_exams.correction_needs_approval', $this->contexts->forOrganization($unit))) {
                $this->apply($company, $correction, $actor, null);
            }

            return $correction->refresh();
        });
    }

    public function decideCorrection(Organization $company, Correction $correction, bool $approve, ?string $note, int $baseVersion, User $actor): Correction
    {
        return $this->office->transaction($company, function () use ($company, $correction, $approve, $note, $baseVersion, $actor) {
            /** @var Correction $correction */
            $correction = $this->office->query(Correction::class, $company)->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();
            if ($correction->version !== $baseVersion) {
                throw ExamException::versionConflict(['version' => $correction->version]);
            }
            if ($correction->status !== 'pending') {
                throw ExamException::wrongStatus($correction->status);
            }
            if ($correction->requested_by === $actor->getKey()) {
                throw ExamException::ownApproval();
            }
            if ($approve) {
                $this->apply($company, $correction, $actor, $note);
            } else {
                $correction->forceFill(['status' => 'rejected', 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note, 'version' => $correction->version + 1])->save();
                $this->audit->record('education_exams.correction_rejected', $correction, reason: $note, actor: $actor, organizationId: $company->getKey());
            }

            return $correction;
        });
    }

    /**
     * Stores results of a source (an exam or a combination): replaces drafts,
     * updates published ones in place (corrections), and sets positions.
     *
     * @param  array<string, array{unit_id: string, level_id: string, section_id: ?string, subjects: list<array<string, mixed>>}>  $students
     */
    private function store(Organization $company, string $type, string $sourceId, string $sessionId, string $schemeId, array $students): int
    {
        $scheme = $this->office->query(GradingScheme::class, $company)->findOrFail($schemeId);
        $bands = $this->grading->bands($company, [$schemeId])[$schemeId] ?? [];

        return $this->office->transaction($company, function () use ($company, $type, $sourceId, $sessionId, $scheme, $bands, $students) {
            $existing = $this->office->query(Result::class, $company)->where('source_type', $type)->where('source_id', $sourceId)->get()->keyBy('student_id');
            $computed = [];
            foreach ($students as $studentId => $row) {
                $unit = Organization::query()->find($row['unit_id']) ?? $company;
                $context = $this->contexts->forOrganization($unit);
                $bonus = $this->rules->get('education_exams.optional_bonus_above', $context);
                $gpa = ExamMath::gpa($row['subjects'], $scheme->max_point_centi, $bonus === null ? null : (int) $bonus,
                    (bool) $this->rules->get('education_exams.gpa_by_credits', $context), (bool) $this->rules->get('education_exams.fail_any_subject', $context));
                $marks = (int) array_sum(array_column($row['subjects'], 'marks_centi'));
                $full = (int) array_sum(array_column($row['subjects'], 'full_marks_centi'));
                $percent = ExamMath::percentBp($marks, $full);
                $computed[$studentId] = [
                    'unit_id' => $row['unit_id'], 'level_id' => $row['level_id'], 'section_id' => $row['section_id'], 'marks_centi' => $marks, 'full_marks_centi' => $full,
                    'percent_bp' => $percent, 'gpa_centi' => $scheme->max_point_centi > 0 ? $gpa['gpa_centi'] : null, 'passed' => $gpa['passed'], 'failed_subjects' => $gpa['failed'],
                    'letter' => $scheme->max_point_centi > 0 ? ExamMath::letterFor($bands, $gpa['gpa_centi'], $gpa['passed']) : ($gpa['passed'] ? (ExamMath::band($bands, $percent)['letter'] ?? null) : (ExamMath::band($bands, 0)['letter'] ?? null)),
                    'credits_centi' => (int) array_sum(array_column($row['subjects'], 'credits_centi')), 'subjects' => $row['subjects'],
                ];
            }
            // Positions in the class (all its sections) and in each section.
            $classRanks = [];
            foreach (collect($computed)->groupBy('level_id', preserveKeys: true) as $group) {
                $classRanks += ExamMath::ranks($group->all());
            }
            $sectionRanks = [];
            foreach (collect($computed)->groupBy('section_id', preserveKeys: true) as $group) {
                $sectionRanks += ExamMath::ranks($group->all());
            }
            foreach ($computed as $studentId => $row) {
                $result = $existing[$studentId] ?? new Result(['organization_id' => $company->getKey(), 'source_type' => $type, 'source_id' => $sourceId, 'student_id' => $studentId, 'session_id' => $sessionId, 'status' => 'draft']);
                $result->fill([...collect($row)->except('subjects')->all(), 'rank' => $classRanks[$studentId] ?? null, 'section_rank' => $sectionRanks[$studentId] ?? null]);
                $result->save();
                $this->office->query(ResultSubject::class, $company)->where('result_id', $result->getKey())->delete();
                foreach ($row['subjects'] as $subject) {
                    (new ResultSubject)->fill(['organization_id' => $company->getKey(), 'result_id' => $result->getKey(), ...collect($subject)->only([
                        'subject_id', 'paper_id', 'kind', 'marks_centi', 'full_marks_centi', 'percent_bp', 'letter', 'point_centi', 'credits_centi', 'passed', 'absent',
                    ])->all()])->save();
                }
            }

            return count($computed);
        });
    }

    private function apply(Organization $company, Correction $correction, User $actor, ?string $note): void
    {
        $mark = $this->office->query(Mark::class, $company)->where('sheet_id', $correction->sheet_id)->where('student_id', $correction->student_id)->where('part_id', $correction->part_id)->first()
            ?? new Mark(['organization_id' => $company->getKey(), 'sheet_id' => $correction->sheet_id, 'part_id' => $correction->part_id, 'student_id' => $correction->student_id,
                'paper_id' => $this->office->query(MarkSheet::class, $company)->whereKey($correction->sheet_id)->value('paper_id')]);
        $mark->fill(['marks_centi' => $correction->new_marks_centi, 'absent' => $correction->new_absent, 'entered_by' => $actor->getKey()])->save();
        $correction->forceFill(['status' => 'applied', 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note, 'version' => $correction->version + 1])->save();
        $this->audit->record('education_exams.correction_applied', $correction, old: ['marks_centi' => $correction->old_marks_centi, 'absent' => $correction->old_absent],
            new: ['marks_centi' => $correction->new_marks_centi, 'absent' => $correction->new_absent], reason: $correction->reason, actor: $actor, organizationId: $company->getKey());

        $exam = $this->office->query(Exam::class, $company)->findOrFail($correction->exam_id);
        $this->computeExam($company, $exam);
        foreach ($this->office->query(Combination::class, $company)->where('session_id', $exam->session_id)->get() as $combination) {
            if (collect($combination->exams)->contains('exam_id', $exam->getKey())) {
                $this->computeCombination($company, $combination);
            }
        }
        DB::afterCommit(fn () => event(new MarksCorrected($company->getKey(), $exam->getKey(), $correction->student_id)));
    }

    /** @return Collection<int, ResultSubject> */
    public function subjectsOf(Organization $company, array $resultIds): Collection
    {
        return $this->office->query(ResultSubject::class, $company)->whereIn('result_id', $resultIds)->get();
    }
}
