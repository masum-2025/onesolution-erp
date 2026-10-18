<?php

namespace Modules\StudentAttendance\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Attendance errors: a translated message (student_attendance::attendance.errors.*)
 * that says what to do next, and a stable code.
 */
class StudentAttendanceException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'student_attendance::attendance.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function notFound(string $what): self
    {
        return new self("{$what}_not_found", 404);
    }

    /** Someone saved this sheet (or another one for the same slot) in the meantime. */
    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }

    /** A teacher takes attendance only of their own section (class teacher) or own subject's period. */
    public static function notYourSection(): self
    {
        return new self('not_your_section', 403, [], ['field' => 'section_id']);
    }

    public static function futureDay(): self
    {
        return new self('future_day', 422, [], ['field' => 'date']);
    }

    /** Older than the rule student_attendance.edit_days lets a teacher go back. */
    public static function outsideWindow(int $days): self
    {
        return new self('outside_window', 422, ['days' => (string) $days], ['field' => 'date']);
    }

    public static function outsideSession(): self
    {
        return new self('outside_session', 422, [], ['field' => 'date']);
    }

    /** By period (rule on): the sheet names a period. */
    public static function periodNeeded(): self
    {
        return new self('period_needed', 422, [], ['field' => 'period_id']);
    }

    /** Once a day (rule off): no period. */
    public static function periodNotUsed(): self
    {
        return new self('period_not_used', 422, [], ['field' => 'period_id']);
    }

    public static function periodInactive(): self
    {
        return new self('period_inactive', 422, [], ['field' => 'period_id']);
    }

    /** A period sheets name is switched off, not deleted. */
    public static function periodInUse(): self
    {
        return new self('period_in_use', 409);
    }

    public static function subjectNotInSection(): self
    {
        return new self('subject_not_in_section', 422, [], ['field' => 'subject_id']);
    }

    /** @param  list<string>  $studentIds */
    public static function studentNotInSection(array $studentIds): self
    {
        return new self('student_not_in_section', 422, [], ['field' => 'marks', 'students' => $studentIds]);
    }
}
