<?php

namespace Modules\Payroll\Contracts;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Amounts another module recovers from employees' pay (an expense advance
 * not spent): Payroll takes each line off the slip as a deduction when it
 * calculates a run. Payroll knows only this contract, never the module. A
 * source tags itself "payroll.deduction_sources" in its service provider
 * and answers nothing where its module is off; it learns the month was paid
 * from the PayrollApproved event.
 */
interface DeductionSource
{
    /**
     * Each employee's deduction lines for the days from-to (inclusive): a code
     * (capitals, e.g. ADVANCE), a name in each language, the amount in minor
     * units of the company's currency, and where it goes in the books (a
     * posting key the source declares, e.g. the advance it settles; else
     * deductions payable). Employees with nothing are left out.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{code: string, name: array<string, string>, amount: int, posting_key?: string|null}>>
     */
    public function deductions(Organization $company, array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array;
}
