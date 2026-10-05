<?php

namespace Modules\Inventory\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;

/** How many items are at or below their reorder level in the warehouses in scope. */
final class LowStock implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        [, $company, $warehouses] = InventoryWidgets::scope($context);

        return WidgetData::stat($company === null ? 0 : InventoryWidgets::lowCount($company, $warehouses), hint: __('inventory::dashboard.low_hint'));
    }
}
