<?php

namespace Modules\Education\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Education errors: a translated message (education::education.errors.*)
 * that says what to do next, and a stable code.
 */
class EducationException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'education::education.errors.'.$this->errorCode;
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

    /** A reference to something of another program, session or campus. */
    public static function mismatch(string $field): self
    {
        return new self("mismatch_{$field}", 422, [], ['field' => $field]);
    }

    public static function inUse(string $what): self
    {
        return new self("{$what}_in_use", 409);
    }

    public static function sectionFull(int $capacity): self
    {
        return new self('section_full', 409, ['capacity' => (string) $capacity]);
    }

    public static function duplicateStudent(string $code): self
    {
        return new self('duplicate_student', 409, ['code' => $code], ['field' => 'birth_registration_no']);
    }

    public static function alreadyEnrolled(): self
    {
        return new self('already_enrolled', 409);
    }

    public static function unknownPreset(): self
    {
        return new self('unknown_preset', 404);
    }

    public static function badPhoto(): self
    {
        return new self('bad_photo', 422, [], ['field' => 'photo']);
    }
}
