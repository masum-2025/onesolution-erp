<?php

namespace Modules\Recruitment\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Recruitment errors: a translated message (recruitment::recruitment.errors.*)
 * that says what to do next, and a stable code.
 */
class RecruitmentException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'recruitment::recruitment.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function openingNotFound(): self
    {
        return new self('opening_not_found', 404);
    }

    public static function applicationNotFound(): self
    {
        return new self('application_not_found', 404);
    }

    public static function interviewNotFound(): self
    {
        return new self('interview_not_found', 404);
    }

    public static function fileNotFound(): self
    {
        return new self('file_not_found', 404);
    }

    public static function notOpen(): self
    {
        return new self('not_open', 409);
    }

    public static function finished(): self
    {
        return new self('finished', 409);
    }

    public static function alreadyApplied(): self
    {
        return new self('already_applied', 409);
    }

    public static function tooManyApplications(): self
    {
        return new self('too_many_applications', 429);
    }

    public static function cannotFeedback(): self
    {
        return new self('cannot_feedback', 403);
    }

    public static function slugTaken(): self
    {
        return new self('slug_taken', 422);
    }

    public static function offerNotFound(): self
    {
        return new self('offer_not_found', 404);
    }

    public static function offerExists(): self
    {
        return new self('offer_exists', 409);
    }

    public static function ownOffer(): self
    {
        return new self('own_offer', 403);
    }

    public static function noCareersAddress(): self
    {
        return new self('no_careers_address', 422);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }
}
