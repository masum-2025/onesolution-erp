<?php

namespace Modules\Hrm\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * HRM errors: a translated message (hrm::hrm.errors.*) and a stable code.
 */
class HrmException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'hrm::hrm.errors.'.$this->errorCode;
    }

    public static function notFound(): self
    {
        return new self('not_found', 404);
    }

    public static function unknownStep(): self
    {
        return new self('unknown_step', 404);
    }

    public static function positionNotFound(): self
    {
        return new self('position_not_found', 404);
    }

    public static function documentNotFound(): self
    {
        return new self('document_not_found', 404);
    }

    /**
     * @param  array<string, mixed>  $current  The record as it is now.
     */
    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function otherCompany(): self
    {
        return new self('other_company', 422);
    }

    public static function notEmployed(): self
    {
        return new self('not_employed', 422);
    }

    public static function notOnProbation(): self
    {
        return new self('not_on_probation', 422);
    }

    public static function alreadyEmployed(): self
    {
        return new self('already_employed', 422);
    }

    public static function sameUnit(): self
    {
        return new self('same_unit', 422);
    }

    public static function samePosition(): self
    {
        return new self('same_position', 422);
    }

    public static function customFieldNotFound(): self
    {
        return new self('custom_field_not_found', 404);
    }

    public static function tooManyFields(int $max): self
    {
        return new self('too_many_fields', 422, ['max' => $max]);
    }

    public static function customFieldElsewhere(string $unit): self
    {
        return new self('custom_field_elsewhere', 403, ['unit' => $unit]);
    }

    public static function importNotFound(): self
    {
        return new self('import_not_found', 404);
    }

    public static function importBusy(): self
    {
        return new self('import_busy', 409);
    }

    public static function importNotReady(string $status): self
    {
        return new self('import_not_ready', 409, ['status' => $status]);
    }

    public static function importHasInvalid(): self
    {
        return new self('import_has_invalid', 422);
    }
}
