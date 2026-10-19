<?php

namespace Modules\Payroll\Contracts;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Days another module says an employee is to be paid for (unused leave paid
 * out): half days, never money. Payroll works the amount out from the
 * employee's pay (payroll.encashment_base over payroll.encashment_day_divisor
 * a day) and adds it to the slip of the run whose days include the date the
 * module gives. A source tags itself "payroll.days_to_pay" and answers
 * nothing where its module is off.
 */
interface DaysToPay
{
    /**
     * Each employee's lines due in from-to (inclusive): a code (capitals),
     * a name in each language, half days (2 = one day) and whether taxable.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{code: string, name: array<string, string>, halves: int, taxable: bool}>>
     */
    public function daysToPay(Organization $company, array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array;
}
