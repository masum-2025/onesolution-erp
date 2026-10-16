<?php

namespace Modules\FactoryErp\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\FactoryErp\Models\Issue;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Receipt;
use Modules\FactoryErp\Services\Floor;

/**
 * What the unit's open orders hold in work in progress: materials on the
 * floor less finished goods taken into the store, in the company's money.
 */
final class WorkInProgress implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = FactoryWidgets::place($context);
        if ($place === null) {
            return WidgetData::stat(0, format: 'money');
        }
        [$company, $units] = $place;
        $floor = app(Floor::class);
        $orders = $floor->query(Order::class, $company)->whereIn('unit_id', $units)->whereIn('status', ['confirmed', 'in_production'])->pluck('id');
        $issued = (int) $floor->query(Issue::class, $company)->whereIn('order_id', $orders)->where('kind', 'issue')->sum('cost_minor');
        $returned = (int) $floor->query(Issue::class, $company)->whereIn('order_id', $orders)->where('kind', 'return')->sum('cost_minor');
        $received = (int) $floor->query(Receipt::class, $company)->whereIn('order_id', $orders)->sum('cost_minor');

        return WidgetData::stat(max(0, $issued - $returned - $received), hint: trans_choice('factory_erp::dashboard.wip_hint', $orders->count(), ['count' => $orders->count()]),
            format: 'money', currency: rescue(fn () => $floor->currency($company), null, false));
    }
}
