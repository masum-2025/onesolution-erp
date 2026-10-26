<?php

namespace Modules\Expenses\Payroll;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Expenses\Services\Claims;
use Modules\Payroll\Contracts\EarningSource;

/**
 * Approved claims to be paid back with a month's salary, for Payroll's
 * slips (its EarningSource contract): one line per employee, not taxed,
 * posted against "owed to employees" (the expense was booked when the claim
 * was approved, so it is not a salary cost again).
 */
final class ClaimsWithSalary implements EarningSource
{
    public function earnings(Organization $company, array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! app(ModuleResolver::class)->isEnabled('expenses', $company)) {
            return [];
        }
        $lines = [];
        foreach (app(Claims::class)->dueWithSalary($company, $employeeIds, $from->format('Y-m')) as $employeeId => $amount) {
            $lines[$employeeId][] = [
                'code' => 'EXPENSES',
                'name' => ['en' => 'Expenses paid back', 'bn' => 'খরচের টাকা ফেরত'],
                'amount' => $amount,
                'taxable' => false,
                'posting_key' => 'expenses.claims_payable',
            ];
        }

        return $lines;
    }
}
