<?php

namespace Modules\FactoryErp\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;
use Modules\FactoryErp\Services\Floor;

/**
 * Pieces in open orders (confirmed or in production) at the unit; in the
 * bell, confirmed orders whose ship date is within the rule's warning days
 * and that have not gone into production.
 */
final class FactoryWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = self::place($context);
        if ($place === null) {
            return WidgetData::stat(0);
        }
        [$company, $units] = $place;
        $floor = app(Floor::class);
        $open = $floor->query(Order::class, $company)->whereIn('unit_id', $units)->whereIn('status', ['confirmed', 'in_production'])->pluck('id');
        $pieces = (int) $floor->query(OrderLine::class, $company)->whereIn('order_id', $open)->sum('quantity');

        return WidgetData::stat($pieces, hint: trans_choice('factory_erp::dashboard.open_hint', $open->count(), ['count' => $open->count()]));
    }

    public function items(CurrentContext $context): array
    {
        $place = self::place($context);
        if ($place === null || ! Gate::allows('factory_erp.merchandise', $context->organization())) {
            return [];
        }
        [$company, $units] = $place;
        $floor = app(Floor::class);
        $days = (int) app(RuleResolver::class)->get('factory_erp.ship_warning_days', app(RuleContextFactory::class)->forOrganization($context->organization()));
        $late = $floor->query(Order::class, $company)->whereIn('unit_id', $units)->where('status', 'confirmed')
            ->where('ship_on', '<=', $floor->today($company)->addDays($days)->toDateString())->count();

        return $late === 0 ? [] : [new AttentionItem('factory_erp.ship_soon', __('factory_erp::dashboard.attention_ship_soon'), $late, '/factory?status=confirmed', 'warn')];
    }

    /** @return array{0: Organization, 1: list<string>}|null */
    public static function place(CurrentContext $context): ?array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return null;
        }
        $floor = app(Floor::class);

        return [$floor->companyOf($unit), $floor->subtreeIds($unit)];
    }
}
