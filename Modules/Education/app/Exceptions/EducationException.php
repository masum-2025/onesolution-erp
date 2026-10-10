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

    public static function nothingToPromote(): self
    {
        return new self('nothing_to_promote', 422);
    }

    /** These students are already on a list that is not applied yet. */
    public static function promotionOpen(): self
    {
        return new self('promotion_open', 409);
    }

    /** The person who made or handed in a list cannot approve it. */
    public static function ownPromotion(): self
    {
        return new self('own_promotion', 403);
    }

    public static function changedSince(): self
    {
        return new self('changed_since', 409);
    }

    public static function undoTooLate(): self
    {
        return new self('undo_too_late', 409);
    }

    public static function undoMovedOn(): self
    {
        return new self('undo_moved_on', 409);
    }

    /** An application to admit has no program, level and session yet. */
    public static function notPlaced(): self
    {
        return new self('not_placed', 422);
    }

    public static function badPhoto(): self
    {
        return new self('bad_photo', 422, [], ['field' => 'photo']);
    }

    public static function templateNotActive(): self
    {
        return new self('template_not_active', 409);
    }

    /** A valid document of the kind exists; replacing it must be asked for. */
    public static function documentExists(string $name, string $kind, string $number, string $studentId): self
    {
        return new self('document_exists', 409, ['name' => $name, 'kind' => $kind, 'number' => $number], ['student_id' => $studentId, 'number' => $number]);
    }

    public static function documentRevoked(): self
    {
        return new self('document_revoked', 409);
    }

    public static function badAsset(int $kb): self
    {
        return new self('bad_asset', 422, ['kb' => (string) $kb], ['field' => 'file']);
    }

    public static function notActiveStudent(string $name, string $status): self
    {
        return new self('not_active_student', 409, ['name' => $name, 'status' => $status]);
    }

    public static function unknownDocumentPreset(): self
    {
        return new self('unknown_document_preset', 404);
    }

    /** One answer for a wrong code, an unknown institution or Education switched off. */
    public static function verifyNotFound(): self
    {
        return new self('verify_not_found', 404);
    }
}
