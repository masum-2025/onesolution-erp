<?php

namespace Modules\Letters\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Letters errors: a translated message (letters::letters.errors.*) that says
 * what to do next, and a stable code.
 */
class LettersException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'letters::letters.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function templateNotFound(): self
    {
        return new self('template_not_found', 404);
    }

    public static function letterNotFound(): self
    {
        return new self('letter_not_found', 404);
    }

    public static function unknownPreset(): self
    {
        return new self('unknown_preset', 404);
    }

    public static function notActive(): self
    {
        return new self('template_not_active', 409);
    }

    public static function salaryOff(): self
    {
        return new self('salary_off', 403);
    }

    public static function salaryNotAllowed(): self
    {
        return new self('salary_not_allowed', 403);
    }

    /** Values missing for some employees: who, and which fields. */
    public static function missingValues(string $people): self
    {
        return new self('missing_values', 422, ['people' => $people]);
    }

    public static function sourceNotFound(): self
    {
        return new self('source_not_found', 404);
    }

    /** The record cannot give letters yet: why, in its module's words. */
    public static function sourceRefused(string $why): self
    {
        return new self('source_refused', 409, ['why' => $why]);
    }

    public static function alreadyRevoked(): self
    {
        return new self('already_revoked', 409);
    }

    public static function badAsset(int $maxKb): self
    {
        return new self('bad_asset', 422, ['max' => $maxKb]);
    }

    public static function unknownStep(): self
    {
        return new self('unknown_step', 404);
    }

    /**
     * @param  array<string, mixed>  $current
     */
    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }
}
