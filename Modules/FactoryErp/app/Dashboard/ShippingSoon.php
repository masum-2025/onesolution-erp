<?php

namespace Modules\FactoryErp\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Crm\Services\Organizations;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;
use Modules\FactoryErp\Models\Style;
use Modules\FactoryErp\Services\Floor;

/**
 * Open orders shipping within the rule factory_erp.ship_warning_days,
 * soonest first: order number and style, buyer and pieces, the ship date;
 * each opens its order. Those not yet in production are marked.
 */
final class ShippingSoon implements DashboardWidget
{
    private const SHOWN = 8;

    public function data(CurrentContext $context): array
    {
        $place = FactoryWidgets::place($context);
        if ($place === null) {
            return WidgetData::list([]);
        }
        [$company, $units] = $place;
        $floor = app(Floor::class);
        $days = (int) app(RuleResolver::class)->get('factory_erp.ship_warning_days', app(RuleContextFactory::class)->forOrganization($context->organization()));
        $orders = $floor->query(Order::class, $company)->whereIn('unit_id', $units)->whereIn('status', ['confirmed', 'in_production'])
            ->where('ship_on', '<=', $floor->today($company)->addDays($days)->toDateString())->orderBy('ship_on')->limit(self::SHOWN)->get();
        $pieces = $floor->query(OrderLine::class, $company)->whereIn('order_id', $orders->pluck('id'))->get(['order_id', 'quantity'])->groupBy('order_id');
        $styles = $floor->query(Style::class, $company)->whereKey($orders->pluck('style_id')->unique())->pluck('code', 'id');
        $buyers = app(Organizations::class)->many($company, $orders->pluck('buyer_id')->unique()->values()->all());

        return WidgetData::list($orders->map(fn (Order $order) => [
            'label' => "{$order->number} · ".($styles[$order->style_id] ?? '—'),
            'meta' => trim(($buyers[$order->buyer_id]['name'] ?? '').' · '.__('factory_erp::dashboard.pieces', ['count' => (int) collect($pieces[$order->getKey()] ?? [])->sum('quantity')]), ' ·'),
            'date' => $order->ship_on->toDateString(),
            'path' => "/factory/orders/{$order->getKey()}",
            'tone' => $order->status === 'confirmed' ? 'warn' : null,
        ])->all());
    }
}
