<?php

namespace Modules\EducationFees\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\CourseRegistration\Directory\RegistrationDirectory;
use Modules\CourseRegistration\Events\RegistrationApproved;
use Modules\EducationFees\Services\Billing;

/**
 * An approved course registration bills its per-credit fees (or the
 * difference since the last approval). Only when fees and course
 * registration are both on; queued, so approving never waits for billing.
 */
class BillCredits implements ShouldQueue
{
    public function __construct(private Billing $billing, private ModuleResolver $modules) {}

    public function handle(RegistrationApproved $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company === null || ! $this->modules->isEnabled('education_fees', $company) || ! $this->modules->isEnabled('course_registration', $company)) {
            return;
        }
        $registration = app(RegistrationDirectory::class)->registration($company, $event->registrationId);
        if ($registration === null || $registration['status'] !== 'approved') {
            return;
        }
        $this->billing->billCredits($company, $registration['student_id'], $registration['session_id'], $registration['credits_centi']);
    }
}
