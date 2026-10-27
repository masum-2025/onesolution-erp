<?php

namespace Modules\Expenses\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Expense errors: a translated message (expenses::expenses.errors.*) that
 * says what to do next, and a stable code.
 */
class ExpensesException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'expenses::expenses.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function notLinked(): self
    {
        return new self('not_linked', 422);
    }

    public static function employeeNotFound(): self
    {
        return new self('employee_not_found', 404);
    }

    public static function categoryNotFound(): self
    {
        return new self('category_not_found', 404);
    }

    public static function categoryClosed(): self
    {
        return new self('category_closed', 422);
    }

    public static function claimNotFound(): self
    {
        return new self('claim_not_found', 404);
    }

    public static function advanceNotFound(): self
    {
        return new self('advance_not_found', 404);
    }

    public static function receiptNotFound(): self
    {
        return new self('receipt_not_found', 404);
    }

    public static function notDraft(): self
    {
        return new self('not_draft', 409);
    }

    public static function emptyClaim(): self
    {
        return new self('empty_claim', 422);
    }

    public static function receiptNeeded(int $line): self
    {
        return new self('receipt_needed', 422, ['line' => (string) $line]);
    }

    public static function cannotDecide(): self
    {
        return new self('cannot_decide', 403);
    }

    public static function notPayable(): self
    {
        return new self('not_payable', 409);
    }

    public static function payrollOff(): self
    {
        return new self('payroll_off', 422);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }
}
