<?php

namespace Modules\Payroll\Contracts;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Earnings another module works out for employees (a factory's piece
 * rates, a shop's sales commission): Payroll adds each line to the slip
 * when it calculates a run, before tax. Payroll knows only this contract,
 * never the module. A source tags itself "payroll.earning_sources" in its
 * service provider and answers nothing where its module is off.
 */
interface EarningSource
{
    /**
     * Each employee's earning lines for the days from-to (inclusive):
     * a code (capitals, e.g. PIECE_RATE), a name in each language, the
     * amount in minor units of the company's currency, and whether it is
     * taxable. Employees with nothing are left out.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{code: string, name: array<string, string>, amount: int, taxable: bool}>>
     */
    public function earnings(Organization $company, array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array;
}
