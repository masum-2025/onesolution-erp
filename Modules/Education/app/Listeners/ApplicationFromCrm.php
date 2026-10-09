<?php

namespace Modules\Education\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Crm\Events\DealWon;
use Modules\Crm\Services\Customers;
use Modules\Education\Services\Admissions;

/**
 * A won deal of an admissions pipeline (rule education.crm_admission_pipelines)
 * becomes an application, once per deal: the contact (usually a parent) as
 * the applicant's guardian, without a place yet. The office places it and
 * fills in the student before admitting. Nothing while Education is off at
 * the deal's campus.
 */
class ApplicationFromCrm
{
    public function __construct(
        private Admissions $admissions,
        private Customers $customers,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function handle(DealWon $event): void
    {
        $company = Organization::query()->find($event->companyId);
        $unit = Organization::query()->find($event->unitId);
        if ($company === null || $unit === null || ! $this->modules->isEnabled('education', $unit)) {
            return;
        }
        $pipelines = (array) $this->rules->get('education.crm_admission_pipelines', $this->contexts->forOrganization($unit));
        if (! in_array($event->pipelineKey, $pipelines, true)) {
            return;
        }
        $contact = $this->customers->contact($company, $event->contactId);
        if ($contact === null) {
            return;
        }

        $this->admissions->create($company, $event->unitId, [
            'applicant' => [
                'name' => $contact['name'],
                'guardians' => [['name' => $contact['name'], 'phone' => $contact['phone'], 'email' => $contact['email'], 'relation' => 'guardian']],
            ],
            'note' => __('education::education.messages.from_crm'),
        ], null, 'crm', $event->dealId);
    }
}
