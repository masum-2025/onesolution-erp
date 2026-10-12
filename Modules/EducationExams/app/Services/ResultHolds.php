<?php

namespace Modules\EducationExams\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\EducationFees\Directory\FeeDirectory;

/**
 * Results held from families: with rule
 * education_exams.withhold_results_with_dues and fees on, a student who
 * owes fees (FeeDirectory) sees no result in the portal and families are
 * told to contact the office instead of the grades. Staff see every result.
 */
class ResultHolds
{
    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts, private ModuleResolver $modules) {}

    /**
     * The students whose results are held now.
     *
     * @param  list<string>  $studentIds
     * @return list<string>
     */
    public function held(Organization $company, Organization $unit, array $studentIds): array
    {
        if ($studentIds === [] || ! $this->rules->get('education_exams.withhold_results_with_dues', $this->contexts->forOrganization($unit))) {
            return [];
        }
        if (! class_exists(FeeDirectory::class) || ! $this->modules->isEnabled('education_fees', $company)) {
            return [];
        }

        return array_keys(app(FeeDirectory::class)->owing($company, $studentIds));
    }
}
