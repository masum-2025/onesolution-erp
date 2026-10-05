<?php

namespace Modules\Inventory\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Inventory\Models\Sequence;

/**
 * Document numbers: the kind's prefix (rule inventory.number_prefixes), the
 * year and a running number per kind and year ("GRN-2026-00001"). Taken
 * inside the caller's transaction, so a number is never used twice.
 */
class Numbers
{
    public function __construct(private Inventories $inventories, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function next(Organization $company, string $kind, CarbonImmutable $on): string
    {
        $year = (int) $on->format('Y');
        $sequence = $this->inventories->query(Sequence::class, $company)->where('kind', $kind)->where('year', $year)->lockForUpdate()->first();
        if ($sequence === null) {
            $sequence = new Sequence;
            $sequence->fill(['organization_id' => $company->getKey(), 'kind' => $kind, 'year' => $year, 'next' => 1]);
        }
        $number = $sequence->next;
        $sequence->next = $number + 1;
        $sequence->save();

        $prefixes = (array) $this->rules->get('inventory.number_prefixes', $this->contexts->forOrganization($company));
        $prefix = (string) ($prefixes[$kind] ?? strtoupper(substr($kind, 0, 3)));

        return sprintf('%s-%d-%05d', $prefix, $year, $number);
    }
}
