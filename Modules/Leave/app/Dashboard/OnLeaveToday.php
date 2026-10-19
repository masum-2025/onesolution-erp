<?php

namespace Modules\Leave\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Services\Place;

/** People of the unit (and below it) on approved leave today. */
final class OnLeaveToday implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return WidgetData::stat(0);
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $today = $place->today($company)->toDateString();
        $count = $place->query(LeaveRequest::class, $company)->whereIn('unit_id', $place->subtreeIds($unit))->where('status', LeaveRequest::APPROVED)
            ->where('from_date', '<=', $today)->where('to_date', '>=', $today)->distinct()->count('employee_id');

        return WidgetData::stat($count, hint: __('leave::dashboard.on_leave_hint'));
    }
}
