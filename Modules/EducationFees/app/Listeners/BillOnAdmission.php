<?php

namespace Modules\EducationFees\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Education\Events\StudentAdmitted;
use Modules\EducationFees\Services\Billing;

/**
 * A newly admitted student is billed the "on admission" heads (admission
 * fee, ID card…) when fees are on and the rule education_fees.bill_on_admission
 * allows it. Queued: admitting never waits for billing. A second delivery
 * finds the bill already there (one per student and key).
 */
class BillOnAdmission implements ShouldQueue
{
    public function __construct(private Billing $billing, private ModuleResolver $modules) {}

    public function handle(StudentAdmitted $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company === null || ! $this->modules->isEnabled('education_fees', $company)) {
            return;
        }
        try {
            $this->billing->billAdmission($company, $event->studentId);
        } catch (UniqueConstraintViolationException) {
            // Billed already (the event came twice).
        }
    }
}
