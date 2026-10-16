<?php

namespace Modules\EducationLectures\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Lecture errors: a translated message (education_lectures::lectures.errors.*)
 * that says what to do next, and a stable code.
 */
class LectureException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'education_lectures::lectures.errors.'.$this->errorCode;
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

    /** A teacher posts only for a subject they teach in that section. */
    public static function notYourSubject(): self
    {
        return new self('not_your_subject', 403, [], ['field' => 'subject_id']);
    }

    /** The subject is not taught in the section (not in its curriculum). */
    public static function subjectNotInSection(): self
    {
        return new self('subject_not_in_section', 422, [], ['field' => 'subject_id']);
    }

    /** Whoever posted a lecture does not approve it. */
    public static function ownApproval(): self
    {
        return new self('own_approval', 403);
    }

    public static function tooManyFiles(int $max): self
    {
        return new self('too_many_files', 422, ['max' => (string) $max], ['field' => 'file']);
    }
}
