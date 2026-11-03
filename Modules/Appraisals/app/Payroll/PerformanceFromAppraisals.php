<?php

namespace Modules\Appraisals\Payroll;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Services\Place;
use Modules\Payroll\Contracts\PerformanceSource;

/**
 * Appraisal results into Payroll (its PerformanceSource contract): closed
 * cycles, the company's bands, and each finished review's score and band.
 * Nothing else leaves Appraisals; nothing at all where it is off.
 */
class PerformanceFromAppraisals implements PerformanceSource
{
    public function __construct(
        private Place $place,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function cycles(Organization $company): array
    {
        if (! $this->modules->isEnabled('appraisals', $company)) {
            return [];
        }

        return $this->place->query(Cycle::class, $company)->where('status', Cycle::CLOSED)
            ->orderByDesc('period_to')->limit(40)->get()
            ->map(fn (Cycle $cycle) => [
                'id' => (string) $cycle->getKey(),
                'name' => $cycle->texts('name'),
                'period_from' => $cycle->period_from->toDateString(),
                'period_to' => $cycle->period_to->toDateString(),
            ])->values()->all();
    }

    public function bands(Organization $company): array
    {
        if (! $this->modules->isEnabled('appraisals', $company)) {
            return [];
        }
        $bands = (array) $this->rules->get('appraisals.bands', $this->contexts->forOrganization($company));
        usort($bands, fn (array $a, array $b) => $b['min'] <=> $a['min']);

        return array_map(fn (array $band) => ['key' => (string) $band['key'], 'name' => (array) $band['name']], $bands);
    }

    public function results(Organization $company, string $cycleId): array
    {
        if (! $this->modules->isEnabled('appraisals', $company)
            || ! $this->place->query(Cycle::class, $company)->whereKey($cycleId)->exists()) {
            return [];
        }

        return $this->place->query(Review::class, $company)->where('cycle_id', $cycleId)->where('stage', Review::DONE)
            ->get(['employee_id', 'score', 'band'])
            ->mapWithKeys(fn (Review $review) => [(string) $review->employee_id => [
                'score' => $review->score === null ? null : (int) $review->score,
                'band' => $review->band,
            ]])->all();
    }
}
