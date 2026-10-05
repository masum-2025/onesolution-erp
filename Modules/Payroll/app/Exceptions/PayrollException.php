<?php

namespace Modules\Payroll\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Payroll errors: a translated message (payroll::payroll.errors.*) that says
 * what to do next, and a stable code.
 */
class PayrollException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'payroll::payroll.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function noCurrency(): self
    {
        return new self('no_currency', 409);
    }

    public static function employeeNotFound(): self
    {
        return new self('employee_not_found', 404);
    }

    public static function componentNotFound(): self
    {
        return new self('component_not_found', 404);
    }

    public static function structureNotFound(): self
    {
        return new self('structure_not_found', 404);
    }

    public static function runNotFound(): self
    {
        return new self('run_not_found', 404);
    }

    public static function adjustmentNotFound(): self
    {
        return new self('adjustment_not_found', 404);
    }

    public static function runExists(string $period): self
    {
        return new self('run_exists', 409, ['period' => $period]);
    }

    public static function notDraft(): self
    {
        return new self('not_draft', 409);
    }

    public static function notPending(): self
    {
        return new self('not_pending', 409);
    }

    public static function notApproved(): self
    {
        return new self('not_approved', 409);
    }

    public static function notCalculated(): self
    {
        return new self('not_calculated', 409);
    }

    public static function slipProblems(int $count): self
    {
        return new self('slip_problems', 409, ['count' => (string) $count]);
    }

    public static function ownRun(): self
    {
        return new self('own_run', 403);
    }

    public static function alreadyApproved(): self
    {
        return new self('already_approved', 403);
    }

    public static function loanChanged(): self
    {
        return new self('loan_changed', 409);
    }

    public static function loanNotFound(): self
    {
        return new self('loan_not_found', 404);
    }

    public static function loanNotPending(): self
    {
        return new self('loan_not_pending', 409);
    }

    public static function loanNotActive(): self
    {
        return new self('loan_not_active', 409);
    }

    public static function ownLoan(): self
    {
        return new self('own_loan', 403);
    }

    public static function monthLocked(string $period): self
    {
        return new self('month_locked', 409, ['period' => $period]);
    }

    public static function bonusNotFound(): self
    {
        return new self('bonus_not_found', 404);
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
