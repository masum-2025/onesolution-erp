<?php

namespace Modules\Recruitment\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Modules\Recruitment\Models\Opening;
use Modules\Recruitment\Services\Place;

/** Openings taking applications at the unit (and below it). */
final class OpenJobs implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return WidgetData::stat(0);
        }
        $place = app(Place::class);
        $count = $place->query(Opening::class, $place->companyOf($unit))->whereIn('unit_id', $place->subtreeIds($unit))->where('status', Opening::OPEN)->count();

        return WidgetData::stat($count, hint: __('recruitment::dashboard.open_hint'));
    }
}
