<?php

namespace Modules\EducationExams\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Exam errors: a translated message (education_exams::exams.errors.*) that
 * says what to do next, and a stable code.
 */
class ExamException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'education_exams::exams.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function notFound(string $what): self
    {
        return new self("{$what}_not_found", 404);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }

    public static function wrongStatus(string $status): self
    {
        return new self('wrong_status', 409, ['status' => $status]);
    }

    /** A grading scheme needs a band from 0 % and no two bands from the same percentage. */
    public static function bandsIncomplete(): self
    {
        return new self('bands_incomplete', 422, extra: ['field' => 'bands']);
    }

    /** The parts of a paper add up to its full marks. */
    public static function partsMismatch(string $full): self
    {
        return new self('parts_mismatch', 422, ['full' => $full], ['field' => 'parts']);
    }

    /** An exam opens for marks with at least one paper. */
    public static function noPapers(): self
    {
        return new self('no_papers', 422);
    }

    /** A paper with marks keeps its marks layout. */
    public static function paperHasMarks(): self
    {
        return new self('paper_has_marks', 409);
    }

    /** Marks above a part's full marks. */
    public static function marksTooHigh(string $part, string $full): self
    {
        return new self('marks_too_high', 422, ['part' => $part, 'full' => $full], ['field' => 'marks']);
    }

    /** Only someone entering this sheet, the section's class teacher or an exam manager. */
    public static function notYourSheet(): self
    {
        return new self('not_your_sheet', 403);
    }

    /** Whoever handed marks in does not approve them. */
    public static function ownApproval(): self
    {
        return new self('own_approval', 403);
    }

    /** A sheet is handed in with every student marked or absent. */
    public static function sheetIncomplete(int $missing): self
    {
        return new self('sheet_incomplete', 422, ['count' => (string) $missing], ['missing' => $missing]);
    }

    /** Marks are entered while the exam takes them. */
    public static function examNotMarking(): self
    {
        return new self('exam_not_marking', 409);
    }

    public static function studentNotInSection(): self
    {
        return new self('student_not_in_section', 422, extra: ['field' => 'student_id']);
    }

    /** Results are published when every mark sheet is approved. */
    public static function sheetsNotApproved(int $count): self
    {
        return new self('sheets_not_approved', 409, ['count' => (string) $count], ['waiting' => $count]);
    }

    /** A combination's exam weights add up to 100 %. */
    public static function weightsNotWhole(): self
    {
        return new self('weights_not_whole', 422, extra: ['field' => 'exams']);
    }

    /** A combination is published once all its exams are. */
    public static function examsNotPublished(int $count): self
    {
        return new self('exams_not_published', 409, ['count' => (string) $count]);
    }

    /** The class already has a paper of this subject in the exam. */
    public static function paperExists(): self
    {
        return new self('paper_exists', 422, extra: ['field' => 'subject_id']);
    }
}
