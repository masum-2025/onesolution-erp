<?php

namespace Modules\EducationFees\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Fee errors: a translated message (education_fees::fees.errors.*) that
 * says what to do next, and a stable code.
 */
class FeeException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'education_fees::fees.errors.'.$this->errorCode;
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

    /** A head of another kind than the run bills (a monthly run takes monthly heads). */
    public static function headNotForRun(string $code): self
    {
        return new self('head_not_for_run', 422, ['head' => $code], ['field' => 'head_ids']);
    }

    /** The month is outside the session. */
    public static function periodOutsideSession(string $period): self
    {
        return new self('period_outside_session', 422, ['period' => $period], ['field' => 'period']);
    }

    /** A structure with the same session, campus, programme, class and category is already active. */
    public static function structureOverlaps(string $name): self
    {
        return new self('structure_overlaps', 409, ['name' => $name]);
    }

    public static function structureEmpty(): self
    {
        return new self('structure_empty', 422, extra: ['field' => 'lines']);
    }

    /** Whoever asked for a discount does not approve it. */
    public static function ownApproval(): self
    {
        return new self('own_approval', 403);
    }

    public static function noFineToWaive(): self
    {
        return new self('no_fine', 409);
    }

    /** A head used on a structure or a bill keeps its frequency. */
    public static function headInUse(): self
    {
        return new self('head_in_use', 409, extra: ['field' => 'frequency']);
    }

    public static function invalidIncomeKey(string $key): self
    {
        return new self('invalid_income_key', 422, ['key' => $key], ['field' => 'income_key']);
    }

    /** The run made no bill: nobody studies there, or no structure has amounts for them. */
    public static function nothingToBill(): self
    {
        return new self('nothing_to_bill', 422);
    }

    /** The institution has no currency set (Settings > country and currency). */
    public static function noCurrency(): self
    {
        return new self('no_currency', 422);
    }

    /** The method is not one the institution takes (rule education_fees.payment_methods). */
    public static function methodNotAllowed(string $method): self
    {
        return new self('method_not_allowed', 422, ['method' => $method], ['field' => 'method']);
    }

    /** Part payments are off: a bill is paid in full. */
    public static function partialNotAllowed(string $bill): self
    {
        return new self('partial_not_allowed', 422, ['bill' => $bill], ['field' => 'amount_minor']);
    }

    public static function moreThanOwed(string $bill): self
    {
        return new self('more_than_owed', 422, ['bill' => $bill], ['field' => 'allocations']);
    }

    public static function allocationsExceedAmount(): self
    {
        return new self('allocations_exceed_amount', 422, extra: ['field' => 'allocations']);
    }

    public static function voidPending(): self
    {
        return new self('void_pending', 409);
    }

    /** The receipt left money as an advance that later bills already used: void those first. */
    public static function advanceAlreadyUsed(): self
    {
        return new self('advance_used', 409);
    }

    public static function advanceTooSmall(int $available): self
    {
        return new self('advance_too_small', 422, ['available' => (string) $available], ['field' => 'amount_minor', 'available_minor' => $available]);
    }
}
