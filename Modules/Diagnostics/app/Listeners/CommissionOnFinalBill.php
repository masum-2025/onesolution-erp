<?php

namespace Modules\Diagnostics\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Diagnostics\Services\Commissions;
use Modules\PatientBilling\Events\BillFinalized;

/**
 * A final patient bill with lab tests on it earns the referring doctors
 * their commission (when Diagnostics is on for the company). Once per order,
 * so a second delivery of the event changes nothing.
 */
class CommissionOnFinalBill
{
    public function __construct(private Commissions $commissions, private ModuleResolver $modules) {}

    public function handle(BillFinalized $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company === null || ! $this->modules->isEnabled('diagnostics', $company)) {
            return;
        }
        $this->commissions->accrueForBill($company, $event->billId);
    }
}
