<?php

namespace Modules\Projects\Dashboard;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Projects\Services\Workplace;

/** Today at the signed-in unit: its company's (the first company below a group). */
trait ReadsProjects
{
    private function todayAt(CurrentContext $context): CarbonImmutable
    {
        $unit = $context->organization();
        $place = app(Workplace::class);
        if ($unit->type->value === 'group') {
            $company = Organization::query()->subtreeOf($unit)->where('type', 'company')->orderBy('path')->first();

            return $company === null ? CarbonImmutable::parse(now('UTC')->toDateString(), 'UTC') : $place->today($company);
        }

        return $place->today($place->companyOf($unit));
    }
}
