<?php

namespace Modules\EducationExams\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Exceptions\ExamException;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\Mark;
use Modules\EducationExams\Models\MarkSheet;
use Modules\EducationExams\Models\Paper;
use Modules\EducationExams\Models\PaperPart;

/**
 * Exams and their papers.
 *
 * - An exam (term, final, class test…) of a session at a campus, graded by
 *   one scheme: draft (papers set) -> marking (sheets open for every
 *   section) -> closed (marks kept) -> published (EXM-2). Closed reopens.
 * - A paper is a subject for a class: full and pass marks, parts that add
 *   up to the full marks (one "total" part when none are given), and its
 *   routine. A class's curriculum becomes papers at once. A paper keeps
 *   its marks layout once marks exist.
 */
class Exams
{
    public function __construct(private ExamOffice $office, private AcademicDirectory $academic, private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function save(Organization $company, ?Exam $exam, array $data, User $actor): Exam
    {
        return $this->office->transaction($company, function () use ($company, $exam, $data, $actor) {
            if ($exam !== null) {
                $exam = $this->locked($company, $exam, $data['base_version'] ?? null);
                if ($exam->status === 'published') {
                    throw ExamException::wrongStatus($exam->status);
                }
            }
            $exam ??= new Exam(['organization_id' => $company->getKey(), 'status' => 'draft', 'created_by' => $actor->getKey(), 'version' => 0]);
            $exam->fill(collect($data)->only(['unit_id', 'session_id', 'kind', 'starts_on', 'ends_on', 'grading_scheme_id'])->all());
            if (isset($data['name'])) {
                $exam->putTexts('name', $data['name']);
            }
            $exam->version++;
            $exam->save();
            $this->audit->record('education_exams.exam_saved', $exam, new: $exam->only(['session_id', 'kind', 'status', 'grading_scheme_id']), actor: $actor, organizationId: $company->getKey());

            return $exam;
        });
    }

    /**
     * draft -> marking (opens a sheet for each paper and section), marking
     * -> closed, closed -> marking (reopened).
     */
    public function setStatus(Organization $company, Exam $exam, string $status, int $baseVersion, User $actor, MarkSheets $sheets): Exam
    {
        return $this->office->transaction($company, function () use ($company, $exam, $status, $baseVersion, $actor, $sheets) {
            $exam = $this->locked($company, $exam, $baseVersion);
            $allowed = ['marking' => ['draft', 'closed'], 'closed' => ['marking']][$status] ?? [];
            if (! in_array($exam->status, $allowed, true)) {
                throw ExamException::wrongStatus($exam->status);
            }
            if ($status === 'marking') {
                if (! $this->office->query(Paper::class, $company)->where('exam_id', $exam->getKey())->exists()) {
                    throw ExamException::noPapers();
                }
                $sheets->open($company, $exam);
            }
            $old = $exam->status;
            $exam->forceFill(['status' => $status, 'version' => $exam->version + 1])->save();
            $this->audit->record('education_exams.exam_'.$status, $exam, old: ['status' => $old], new: ['status' => $status], actor: $actor, organizationId: $company->getKey());

            return $exam;
        });
    }

    /**
     * A paper with its parts ({code, name, full_marks_centi, pass_marks_centi?}).
     *
     * @param  array<string, mixed>  $data
     */
    public function savePaper(Organization $company, Exam $exam, ?Paper $paper, array $data, User $actor): Paper
    {
        return $this->office->transaction($company, function () use ($company, $exam, $paper, $data, $actor) {
            if ($exam->status === 'published') {
                throw ExamException::wrongStatus($exam->status);
            }
            if ($paper !== null) {
                $paper = $this->office->query(Paper::class, $company)->whereKey($paper->getKey())->lockForUpdate()->firstOrFail();
                if (isset($data['base_version']) && $paper->version !== (int) $data['base_version']) {
                    throw ExamException::versionConflict(['version' => $paper->version]);
                }
            }
            $marked = $paper !== null && $this->office->query(Mark::class, $company)->where('paper_id', $paper->getKey())->exists();
            if ($marked && (isset($data['parts']) || (isset($data['full_marks_centi']) && (int) $data['full_marks_centi'] !== $paper->full_marks_centi))) {
                throw ExamException::paperHasMarks();
            }
            if ($paper === null && $this->office->query(Paper::class, $company)->where('exam_id', $exam->getKey())->where('level_id', $data['level_id'])->where('subject_id', $data['subject_id'])->exists()) {
                throw ExamException::paperExists();
            }
            $paper ??= new Paper(['organization_id' => $company->getKey(), 'exam_id' => $exam->getKey(), 'version' => 0]);
            $paper->fill(collect($data)->only(['level_id', 'subject_id', 'kind', 'full_marks_centi', 'pass_marks_centi', 'sits_on', 'starts_at', 'minutes', 'room'])->all());
            $paper->version++;
            $paper->save();

            if (isset($data['parts']) || ! $this->office->query(PaperPart::class, $company)->where('paper_id', $paper->getKey())->exists()) {
                $parts = ($data['parts'] ?? []) ?: [['code' => 'TOTAL', 'name' => ['en' => 'Total', 'bn' => 'মোট'], 'full_marks_centi' => $paper->full_marks_centi, 'pass_marks_centi' => null]];
                if (array_sum(array_map(fn (array $part) => (int) $part['full_marks_centi'], $parts)) !== $paper->full_marks_centi) {
                    throw ExamException::partsMismatch(ExamMath::centiText($paper->full_marks_centi));
                }
                $this->office->query(PaperPart::class, $company)->where('paper_id', $paper->getKey())->delete();
                foreach (array_values($parts) as $index => $part) {
                    $row = new PaperPart;
                    $row->fill(['organization_id' => $company->getKey(), 'paper_id' => $paper->getKey(), 'code' => $part['code'], 'full_marks_centi' => (int) $part['full_marks_centi'],
                        'pass_marks_centi' => isset($part['pass_marks_centi']) ? (int) $part['pass_marks_centi'] : null, 'sort_order' => $index]);
                    $row->putTexts('name', $part['name'] ?? ['en' => $part['code']]);
                    $row->save();
                }
            }
            if ($exam->status === 'marking') {
                app(MarkSheets::class)->open($company, $exam);
            }
            $this->audit->record('education_exams.paper_saved', $paper, new: $paper->only(['exam_id', 'level_id', 'subject_id', 'full_marks_centi', 'pass_marks_centi']), actor: $actor, organizationId: $company->getKey());

            return $paper;
        });
    }

    /**
     * A class's curriculum in the exam's session as papers (subjects not
     * already there), each with the same full and pass marks.
     *
     * @return Collection<int, Paper>
     */
    public function papersFromCurriculum(Organization $company, Exam $exam, string $levelId, int $fullCenti, int $passCenti, User $actor): Collection
    {
        $have = $this->office->query(Paper::class, $company)->where('exam_id', $exam->getKey())->where('level_id', $levelId)->pluck('subject_id')->all();
        $made = collect();
        foreach ($this->academic->curriculum($company, $levelId, $exam->session_id) as $item) {
            if (in_array($item['subject_id'], $have, true)) {
                continue;
            }
            $made->push($this->savePaper($company, $exam, null, [
                'level_id' => $levelId, 'subject_id' => $item['subject_id'], 'kind' => $item['kind'] ?? 'compulsory',
                'full_marks_centi' => $fullCenti, 'pass_marks_centi' => $passCenti,
            ], $actor));
        }

        return $made;
    }

    /** A paper without marks is removed (with its parts and empty sheets). */
    public function deletePaper(Organization $company, Paper $paper, User $actor): void
    {
        $this->office->transaction($company, function () use ($company, $paper, $actor) {
            if ($this->office->query(Mark::class, $company)->where('paper_id', $paper->getKey())->exists()) {
                throw ExamException::paperHasMarks();
            }
            $this->office->query(MarkSheet::class, $company)->where('paper_id', $paper->getKey())->delete();
            $this->office->query(PaperPart::class, $company)->where('paper_id', $paper->getKey())->delete();
            $this->audit->record('education_exams.paper_deleted', $paper, old: $paper->only(['exam_id', 'level_id', 'subject_id']), actor: $actor, organizationId: $company->getKey());
            $paper->delete();
        });
    }

    /** @return Collection<int, PaperPart> */
    public function parts(Organization $company, array $paperIds): Collection
    {
        return $this->office->query(PaperPart::class, $company)->whereIn('paper_id', $paperIds)->orderBy('sort_order')->get();
    }

    private function locked(Organization $company, Exam $exam, ?int $baseVersion): Exam
    {
        /** @var Exam $fresh */
        $fresh = $this->office->query(Exam::class, $company)->whereKey($exam->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw ExamException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }
}
