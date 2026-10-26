<?php

namespace Modules\Expenses\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Modules\Expenses\Models\Claim;
use Modules\Expenses\Services\Place;

/** Claims approved this month (company calendar) at the unit and below, as money. */
final class SpentThisMonth implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return WidgetData::stat(0, format: 'money');
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $today = $place->today($company);
        $claims = $place->query(Claim::class, $company)->whereIn('unit_id', $place->subtreeIds($unit))->whereIn('status', [Claim::APPROVED, Claim::PAID])
            ->whereBetween('decided_at', [$today->startOfMonth(), $today->endOfMonth()])->get(['total_minor', 'currency']);

        return WidgetData::stat((int) $claims->sum('total_minor'), hint: __('expenses::dashboard.spent_hint'), format: 'money', currency: $claims->first()?->currency);
    }
}
