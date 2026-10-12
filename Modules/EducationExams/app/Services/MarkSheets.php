<?php

namespace Modules\EducationExams\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Exceptions\ExamException;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\Mark;
use Modules\EducationExams\Models\MarkSheet;
use Modules\EducationExams\Models\Paper;
use Modules\EducationExams\Models\PaperPart;

/**
 * Entering marks, a paper for one section at a time.
 *
 * - When an exam opens for marks, each paper gets a sheet for every active
 *   section of its class in the session at the exam's campus and below.
 * - Who enters a sheet: the HRM employee named on it, else the section's
 *   class teacher (with education_exams.enter_marks); people who manage
 *   exams enter any. Marks are saved as often as needed while the sheet is
 *   open or returned; never above a part's full marks; absent is a mark.
 * - Handed in when every student has every part (or is absent); with rule
 *   education_exams.moderation_required someone else (.moderate, never
 *   who handed it in) approves it or returns it with a note; without it,
 *   handing in approves.
 */
class MarkSheets
{
    public function __construct(
        private ExamOffice $office,
        private AcademicDirectory $academic,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** A sheet for each paper and section that has none yet; how many were opened. */
    public function open(Organization $company, Exam $exam): int
    {
        $unit = Organization::query()->findOrFail($exam->unit_id);
        $units = $this->office->subtreeIds($unit);
        $sections = collect($this->academic->sessionSections($company, $exam->session_id, $units))->groupBy('level_id');
        $have = $this->office->query(MarkSheet::class, $company)->where('exam_id', $exam->getKey())->get(['paper_id', 'section_id'])
            ->map(fn (MarkSheet $sheet) => "{$sheet->paper_id}:{$sheet->section_id}")->flip();
        $count = 0;
        foreach ($this->office->query(Paper::class, $company)->where('exam_id', $exam->getKey())->get() as $paper) {
            foreach ($sections[$paper->level_id] ?? [] as $section) {
                if (isset($have["{$paper->getKey()}:{$section['id']}"])) {
                    continue;
                }
                (new MarkSheet)->fill([
                    'organization_id' => $company->getKey(), 'unit_id' => $section['unit_id'], 'exam_id' => $exam->getKey(), 'paper_id' => $paper->getKey(),
                    'section_id' => $section['id'], 'status' => 'open', 'version' => 1,
                ])->save();
                $count++;
            }
        }

        return $count;
    }

    /** Whether a person may enter (or see to enter) a sheet's marks. */
    public function mayEnter(Organization $company, MarkSheet $sheet, User $user): bool
    {
        $unit = Organization::query()->find($sheet->unit_id) ?? $company;
        if (Gate::forUser($user)->allows('education_exams.manage', $unit)) {
            return true;
        }
        if (! Gate::forUser($user)->allows('education_exams.enter_marks', $unit)) {
            return false;
        }
        $employee = $this->academic->employeeOf($company, $user);
        if ($employee === null) {
            return false;
        }

        return $sheet->teacher_id !== null
            ? $sheet->teacher_id === $employee
            : ($this->academic->section($company, $sheet->section_id)['class_teacher_id'] ?? null) === $employee;
    }

    /**
     * A teacher's own sheets in exams open for marks at these units: named
     * on the sheet, or the class teacher of a sheet that names nobody. By
     * the HRM employee the login belongs to; none linked, none.
     *
     * @param  list<string>  $unitIds
     * @return Collection<int, MarkSheet>
     */
    public function forTeacher(Organization $company, array $unitIds, User $user): Collection
    {
        $employee = $this->academic->employeeOf($company, $user);
        if ($employee === null) {
            return collect();
        }
        // Exams open for marks with sheets here, wherever they were set (an institution's exam covers every campus).
        $exams = $this->office->query(Exam::class, $company)->where('status', 'marking')
            ->whereIn('id', $this->office->query(MarkSheet::class, $company)->whereIn('unit_id', $unitIds)->select('exam_id'))->get(['id', 'session_id']);
        $classSections = $exams->pluck('session_id')->unique()
            ->flatMap(fn (string $session) => $this->academic->sessionSections($company, $session, $unitIds))
            ->where('class_teacher_id', $employee)->pluck('id')->all();

        return $this->office->query(MarkSheet::class, $company)->whereIn('exam_id', $exams->pluck('id'))->whereIn('unit_id', $unitIds)
            ->where(fn ($query) => $query->where('teacher_id', $employee)
                ->orWhere(fn ($query) => $query->whereNull('teacher_id')->whereIn('section_id', $classSections)))
            ->limit(500)->get()
            ->filter(fn (MarkSheet $sheet) => $this->mayEnter($company, $sheet, $user))->values();
    }

    /**
     * The sheet's students (by name) with their marks per part.
     *
     * @return array{students: list<array<string, mixed>>, parts: Collection<int, PaperPart>, marks: array<string, array<string, Mark>>}
     */
    public function entries(Organization $company, MarkSheet $sheet): array
    {
        $students = $this->academic->sectionStudents($company, $sheet->section_id);
        $marks = $this->office->query(Mark::class, $company)->where('sheet_id', $sheet->getKey())->get()
            ->groupBy('student_id')->map(fn (Collection $rows) => $rows->keyBy('part_id')->all())->all();

        return [
            'students' => $students,
            'parts' => $this->office->query(PaperPart::class, $company)->where('paper_id', $sheet->paper_id)->orderBy('sort_order')->get(),
            'marks' => $marks,
        ];
    }

    /**
     * Saves marks: [{student_id, part_id, marks_centi|null, absent}]. A row
     * with neither marks nor absent clears that mark.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function save(Organization $company, MarkSheet $sheet, array $rows, User $actor): MarkSheet
    {
        return $this->office->transaction($company, function () use ($company, $sheet, $rows, $actor) {
            $sheet = $this->locked($company, $sheet);
            $this->assertWritable($company, $sheet, $actor);
            $parts = $this->office->query(PaperPart::class, $company)->where('paper_id', $sheet->paper_id)->get()->keyBy('id');
            $students = array_flip(array_column($this->academic->sectionStudents($company, $sheet->section_id), 'id'));
            foreach ($rows as $row) {
                $part = $parts[$row['part_id']] ?? throw ExamException::notFound('part');
                if (! isset($students[$row['student_id']])) {
                    throw ExamException::studentNotInSection();
                }
                $absent = (bool) ($row['absent'] ?? false);
                $marks = $absent || ! isset($row['marks_centi']) ? null : (int) $row['marks_centi'];
                if ($marks !== null && $marks > $part->full_marks_centi) {
                    throw ExamException::marksTooHigh($part->code, ExamMath::centiText($part->full_marks_centi));
                }
                $existing = $this->office->query(Mark::class, $company)->where('sheet_id', $sheet->getKey())->where('student_id', $row['student_id'])->where('part_id', $part->getKey())->first();
                if ($marks === null && ! $absent) {
                    $existing?->delete();

                    continue;
                }
                ($existing ?? new Mark)->fill([
                    'organization_id' => $company->getKey(), 'sheet_id' => $sheet->getKey(), 'paper_id' => $sheet->paper_id, 'part_id' => $part->getKey(),
                    'student_id' => $row['student_id'], 'marks_centi' => $marks, 'absent' => $absent, 'entered_by' => $actor->getKey(),
                ])->save();
            }
            $sheet->forceFill(['version' => $sheet->version + 1])->save();

            return $sheet;
        });
    }

    /** Handed in (approved at once when moderation is off). */
    public function submit(Organization $company, MarkSheet $sheet, int $baseVersion, User $actor): MarkSheet
    {
        return $this->office->transaction($company, function () use ($company, $sheet, $baseVersion, $actor) {
            $sheet = $this->locked($company, $sheet, $baseVersion);
            $this->assertWritable($company, $sheet, $actor);
            $entries = $this->entries($company, $sheet);
            $missing = 0;
            foreach ($entries['students'] as $student) {
                $mine = $entries['marks'][$student['id']] ?? [];
                $absent = collect($mine)->contains(fn (Mark $mark) => $mark->absent);
                if (! $absent && count($mine) < $entries['parts']->count()) {
                    $missing++;
                }
            }
            if ($missing > 0) {
                throw ExamException::sheetIncomplete($missing);
            }
            $unit = Organization::query()->find($sheet->unit_id) ?? $company;
            $moderated = (bool) $this->rules->get('education_exams.moderation_required', $this->contexts->forOrganization($unit));
            $sheet->forceFill([
                'status' => $moderated ? 'submitted' : 'approved', 'submitted_by' => $actor->getKey(), 'submitted_at' => now(),
                'approved_by' => $moderated ? null : $actor->getKey(), 'approved_at' => $moderated ? null : now(), 'note' => null, 'version' => $sheet->version + 1,
            ])->save();
            $this->audit->record('education_exams.sheet_submitted', $sheet, new: $sheet->only(['paper_id', 'section_id', 'status']), actor: $actor, organizationId: $company->getKey());

            return $sheet;
        });
    }

    /** Someone else approves a handed-in sheet, or returns it with a note. */
    public function decide(Organization $company, MarkSheet $sheet, bool $approve, ?string $note, int $baseVersion, User $actor): MarkSheet
    {
        return $this->office->transaction($company, function () use ($company, $sheet, $approve, $note, $baseVersion, $actor) {
            $sheet = $this->locked($company, $sheet, $baseVersion);
            if ($sheet->status !== 'submitted') {
                throw ExamException::wrongStatus($sheet->status);
            }
            if ($sheet->submitted_by === $actor->getKey()) {
                throw ExamException::ownApproval();
            }
            $sheet->forceFill([
                'status' => $approve ? 'approved' : 'returned', 'approved_by' => $approve ? $actor->getKey() : null, 'approved_at' => $approve ? now() : null,
                'note' => $approve ? null : $note, 'version' => $sheet->version + 1,
            ])->save();
            $this->audit->record('education_exams.sheet_'.($approve ? 'approved' : 'returned'), $sheet, new: $sheet->only(['paper_id', 'section_id', 'status']), reason: $note, actor: $actor, organizationId: $company->getKey());

            return $sheet;
        });
    }

    /** Names who enters a sheet (an HRM employee; empty: the class teacher). */
    public function assign(Organization $company, MarkSheet $sheet, ?string $teacherId, int $baseVersion, User $actor): MarkSheet
    {
        return $this->office->transaction($company, function () use ($company, $sheet, $teacherId, $baseVersion, $actor) {
            $sheet = $this->locked($company, $sheet, $baseVersion);
            $old = $sheet->teacher_id;
            $sheet->forceFill(['teacher_id' => $teacherId, 'version' => $sheet->version + 1])->save();
            $this->audit->record('education_exams.sheet_assigned', $sheet, old: ['teacher_id' => $old], new: ['teacher_id' => $teacherId], actor: $actor, organizationId: $company->getKey());

            return $sheet;
        });
    }

    private function assertWritable(Organization $company, MarkSheet $sheet, User $actor): void
    {
        if (! in_array($sheet->status, ['open', 'returned'], true)) {
            throw ExamException::wrongStatus($sheet->status);
        }
        if ($this->office->query(Exam::class, $company)->whereKey($sheet->exam_id)->value('status') !== 'marking') {
            throw ExamException::examNotMarking();
        }
        if (! $this->mayEnter($company, $sheet, $actor)) {
            throw ExamException::notYourSheet();
        }
    }

    private function locked(Organization $company, MarkSheet $sheet, ?int $baseVersion = null): MarkSheet
    {
        /** @var MarkSheet $fresh */
        $fresh = $this->office->query(MarkSheet::class, $company)->whereKey($sheet->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw ExamException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }
}
