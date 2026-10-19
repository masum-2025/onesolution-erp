<?php

namespace Modules\Leave\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Support\Facades\Gate;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Services\Place;
use Modules\Leave\Services\Requests;

/**
 * Leave requests waiting at the unit (and below it): a count on the
 * dashboard, and in the bell of people who may decide their next step.
 */
final class RequestsWaiting implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return WidgetData::stat(0);
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $count = $place->query(LeaveRequest::class, $company)->whereIn('unit_id', $place->subtreeIds($unit))->where('status', LeaveRequest::PENDING)->count();

        return WidgetData::stat($count, hint: __('leave::dashboard.waiting_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        $user = $context->user();
        if ($user === null || $unit->type === OrganizationType::Group || ! Gate::allows('leave.approve', $unit)) {
            return [];
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $requests = app(Requests::class);
        $count = $place->query(LeaveRequest::class, $company)->whereIn('unit_id', $place->subtreeIds($unit))->where('status', LeaveRequest::PENDING)
            ->limit(200)->get()->filter(fn (LeaveRequest $request) => $requests->canDecide($company, $request, $user))->count();

        return $count === 0 ? [] : [new AttentionItem('leave.requests_waiting', __('leave::dashboard.attention'), $count, '/leave/requests', 'warn')];
    }
}
