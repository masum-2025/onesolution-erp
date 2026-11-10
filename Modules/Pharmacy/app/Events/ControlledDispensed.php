<?php

namespace Modules\Pharmacy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A controlled medicine left the shelf (manifest event "pharmacy.controlled_dispensed"):
 * its register entry. Ids and quantities only.
 */
class ControlledDispensed
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $entryId,
        public string $medicineId,
        public int $quantityMilli,
    ) {}

    public function name(): string
    {
        return 'pharmacy.controlled_dispensed';
    }
}
