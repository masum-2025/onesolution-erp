<?php

namespace Modules\Patients\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Patients errors: a translated message (patients::messages.errors.*) that says what to do next, and a stable code.
 */
class PatientsException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'patients::messages.errors.'.$this->errorCode;
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

    public static function noCurrency(): self
    {
        return new self('no_currency', 422);
    }

    /**
     * Patients that look like the one being registered; sent again with confirm_new it is made anyway.
     *
     * @param  list<array<string, mixed>>  $matches
     */
    public static function possibleDuplicate(array $matches): self
    {
        return new self('possible_duplicate', 409, extra: ['matches' => $matches]);
    }

    public static function idNumberRequired(): self
    {
        return new self('id_number_required', 422);
    }

    public static function badMobile(): self
    {
        return new self('bad_mobile', 422);
    }

    public static function ageOrBirthDate(): self
    {
        return new self('age_or_birth_date', 422);
    }

    /** The record was folded into another; the screens open that one. */
    public static function merged(string $intoId): self
    {
        return new self('patient_merged', 409, extra: ['merged_into_id' => $intoId]);
    }

    public static function mergeSelf(): self
    {
        return new self('merge_self', 422);
    }

    public static function mergeWaiting(): self
    {
        return new self('merge_waiting', 409);
    }

    public static function ownRequest(): self
    {
        return new self('own_request', 403);
    }

    public static function notPending(): self
    {
        return new self('not_pending', 409);
    }
}
