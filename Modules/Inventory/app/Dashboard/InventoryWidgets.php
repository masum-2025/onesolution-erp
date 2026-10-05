<?php

namespace Modules\Inventory\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\BatchStock;
use Modules\Inventory\Models\Document;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\Inventories;

/**
 * Inventory on the dashboard and in the bell, for the unit in the context
 * and its warehouses below: stock value; items at or below their reorder
 * level; batches expiring (rule inventory.expiry_alert_days); adjustments
 * and counts waiting for someone else's approval.
 */
final class InventoryWidgets implements AttentionProvider, DashboardWidget
{
    /** Stock value of the warehouses in scope. */
    public function data(CurrentContext $context): array
    {
        [, $company, $warehouses] = self::scope($context);
        if ($company === null) {
            return WidgetData::stat(0);
        }
        $value = (int) app(Inventories::class)->query(Balance::class, $company)->whereIn('warehouse_id', $warehouses)->sum('value_minor');

        return WidgetData::stat($value, hint: __('inventory::dashboard.value_hint'), format: 'money', currency: app(Inventories::class)->currency($company));
    }

    public function items(CurrentContext $context): array
    {
        [$unit, $company, $warehouses] = self::scope($context);
        if ($company === null || ! Gate::allows('inventory.view', $unit)) {
            return [];
        }
        $inventories = app(Inventories::class);
        $items = [];
        $low = self::lowCount($company, $warehouses);
        if ($low > 0) {
            $items[] = new AttentionItem('inventory.low_stock', __('inventory::dashboard.attention_low'), $low, '/inventory/stock?low=1', 'warn');
        }
        $days = (int) app(RuleResolver::class)->get('inventory.expiry_alert_days', app(RuleContextFactory::class)->forOrganization($unit));
        $held = $inventories->query(BatchStock::class, $company)->whereIn('warehouse_id', $warehouses)->where('quantity_milli', '>', 0)->pluck('batch_id')->unique()->all();
        $expiring = $inventories->query(Batch::class, $company)->whereIn('id', $held)->whereNotNull('expires_on')->where('expires_on', '<=', CarbonImmutable::now()->addDays($days)->toDateString())->count();
        if ($expiring > 0) {
            $items[] = new AttentionItem('inventory.expiring', __('inventory::dashboard.attention_expiring'), $expiring, '/inventory/expiring', 'warn');
        }
        if (Gate::allows('inventory.approve', $unit)) {
            $me = $context->user()?->getKey();
            $waiting = $inventories->query(Document::class, $company)->whereIn('warehouse_id', $warehouses)->where('status', Document::PENDING)
                ->where(fn ($query) => $query->whereNull('created_by')->orWhere('created_by', '!=', $me))->count()
                + $inventories->query(StockCount::class, $company)->whereIn('warehouse_id', $warehouses)->where('status', StockCount::PENDING)
                    ->where(fn ($query) => $query->whereNull('submitted_by')->orWhere('submitted_by', '!=', $me))->count();
            if ($waiting > 0) {
                $items[] = new AttentionItem('inventory.waiting', __('inventory::dashboard.attention_waiting'), $waiting, '/inventory/documents?status=pending_approval', 'warn');
            }
        }

        return $items;
    }

    /** @param  list<string>  $warehouses */
    public static function lowCount(Organization $company, array $warehouses): int
    {
        $inventories = app(Inventories::class);
        $levels = $inventories->query(Item::class, $company)->whereNotNull('reorder_level_milli')->where('is_active', true)->pluck('reorder_level_milli', 'id');
        if ($levels->isEmpty()) {
            return 0;
        }
        $held = $inventories->query(Balance::class, $company)->whereIn('warehouse_id', $warehouses)->whereIn('item_id', $levels->keys()->all())
            ->get(['item_id', 'quantity_milli'])->groupBy('item_id')->map(fn ($rows) => (int) $rows->sum('quantity_milli'));

        return $levels->filter(fn ($level, $itemId) => ($held[$itemId] ?? 0) <= (int) $level)->count();
    }

    /**
     * @return array{0: Organization, 1: Organization|null, 2: list<string>}
     */
    public static function scope(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return [$unit, null, []];
        }
        $inventories = app(Inventories::class);
        $company = $inventories->companyOf($unit);

        return [$unit, $company, $inventories->query(Warehouse::class, $company)->whereIn('unit_id', $inventories->subtreeIds($unit))->pluck('id')->map(fn ($id) => (string) $id)->all()];
    }
}
