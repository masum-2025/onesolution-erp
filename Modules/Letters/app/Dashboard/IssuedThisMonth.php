<?php

namespace Modules\Letters\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Carbon\CarbonImmutable;
use Modules\Letters\Models\Letter;
use Modules\Letters\Services\Place;

/** Letters issued this month to the people of the unit (and below), revoked ones left out. */
final class IssuedThisMonth implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return WidgetData::stat(0);
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $count = $place->query(Letter::class, $company)->whereIn('unit_id', $place->subtreeIds($unit))
            ->where('issued_at', '>=', CarbonImmutable::now('UTC')->startOfMonth())->whereNull('revoked_at')->count();

        return WidgetData::stat($count, hint: __('letters::dashboard.issued_hint'));
    }
}
