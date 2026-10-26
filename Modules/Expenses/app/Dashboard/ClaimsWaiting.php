<?php

namespace Modules\Expenses\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Support\Facades\Gate;
use Modules\Expenses\Models\Claim;
use Modules\Expenses\Services\Claims;
use Modules\Expenses\Services\Place;

/**
 * Claims waiting for a decision at the unit (and below it): a count on the
 * dashboard, and in the bell of the people who may decide their next step.
 */
final class ClaimsWaiting implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return WidgetData::stat(0);
        }
        $place = app(Place::class);
        $count = $place->query(Claim::class, $place->companyOf($unit))->whereIn('unit_id', $place->subtreeIds($unit))->where('status', Claim::SUBMITTED)->count();

        return WidgetData::stat($count, hint: __('expenses::dashboard.waiting_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        $user = $context->user();
        if ($user === null || $unit->type === OrganizationType::Group || ! Gate::any(['expenses.approve', 'expenses.pay', 'expenses.manage'], $unit)) {
            return [];
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        $claims = app(Claims::class);
        $count = $place->query(Claim::class, $company)->whereIn('unit_id', $place->subtreeIds($unit))->where('status', Claim::SUBMITTED)
            ->limit(200)->get()->filter(fn (Claim $claim) => $claims->canDecide($company, $claim, $user))->count();

        return $count === 0 ? [] : [new AttentionItem('expenses.claims_waiting', __('expenses::dashboard.attention'), $count, '/expenses/approvals', 'warn')];
    }
}
