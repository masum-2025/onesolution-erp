<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An item's stock in a warehouse fell to or below its reorder level
 * (manifest event "inventory.stock.low"). Ids and the quantity only.
 */
class StockRanLow
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $itemId,
        public string $warehouseId,
        public int $quantityMilli,
    ) {}

    public function name(): string
    {
        return 'inventory.stock.low';
    }
}
