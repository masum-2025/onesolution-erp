<?php

namespace Modules\Appraisals\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Models\Review;
use Modules\Appraisals\Services\Place;

/** Reviews of open cycles at the unit (and below) not done yet. */
final class ReviewsInProgress implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return WidgetData::stat(0);
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $open = $place->query(Cycle::class, $company)->where('status', Cycle::OPEN)->pluck('id')->all();
        $count = $place->query(Review::class, $company)->whereIn('cycle_id', $open)->whereIn('unit_id', $place->subtreeIds($unit))
            ->where('stage', '!=', Review::DONE)->count();

        return WidgetData::stat($count, hint: __('appraisals::dashboard.in_progress_hint'));
    }
}
