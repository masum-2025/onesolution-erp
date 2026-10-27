<?php

namespace Modules\Appraisals\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Appraisal errors: a translated message (appraisals::appraisals.errors.*)
 * that says what to do next, and a stable code.
 */
class AppraisalsException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'appraisals::appraisals.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function notLinked(): self
    {
        return new self('not_linked', 422);
    }

    public static function cycleNotFound(): self
    {
        return new self('cycle_not_found', 404);
    }

    public static function reviewNotFound(): self
    {
        return new self('review_not_found', 404);
    }

    public static function competencyNotFound(): self
    {
        return new self('competency_not_found', 404);
    }

    public static function notDraft(): self
    {
        return new self('not_draft', 409);
    }

    public static function notOpen(): self
    {
        return new self('not_open', 409);
    }

    public static function nobody(): self
    {
        return new self('nobody', 422);
    }

    public static function wrongStage(): self
    {
        return new self('wrong_stage', 409);
    }

    public static function notYours(): self
    {
        return new self('not_yours', 403);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }
}
