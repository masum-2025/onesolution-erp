<?php

namespace Modules\Expenses\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Expenses\Services\Claims;
use Modules\Payroll\Events\PayrollApproved;

/** A month's payroll was approved: the claims paid back with that month's salary are paid. */
final class PayClaimsWithPayroll
{
    public function handle(PayrollApproved $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company === null || ! app(ModuleResolver::class)->isEnabled('expenses', $company)) {
            return;
        }
        app(Claims::class)->paidWithPayroll($company, $event->period, $event->runId);
    }
}
