<?php

namespace Modules\Expenses\Payroll;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Expenses\Services\Advances;
use Modules\Payroll\Contracts\DeductionSource;

/**
 * What is left of advances to be recovered from a month's salary, for
 * Payroll's slips (its DeductionSource contract): one line per employee,
 * posted against employee advances (the advance it settles), not
 * deductions payable.
 */
final class AdvancesFromSalary implements DeductionSource
{
    public function deductions(Organization $company, array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! app(ModuleResolver::class)->isEnabled('expenses', $company)) {
            return [];
        }
        $lines = [];
        foreach (app(Advances::class)->dueFromSalary($company, $employeeIds, $from->format('Y-m')) as $employeeId => $amount) {
            $lines[$employeeId][] = [
                'code' => 'ADVANCE',
                'name' => ['en' => 'Expense advance recovered', 'bn' => 'খরচের অগ্রিম ফেরত'],
                'amount' => $amount,
                'posting_key' => 'expenses.employee_advances',
            ];
        }

        return $lines;
    }
}
