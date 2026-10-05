<?php

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An inventory document or count was posted (manifest event
 * "inventory.moved"). Ids only.
 */
class StockMoved
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $sourceType,
        public string $sourceId,
    ) {}

    public function name(): string
    {
        return 'inventory.moved';
    }
}
