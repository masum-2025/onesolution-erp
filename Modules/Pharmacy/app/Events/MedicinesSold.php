<?php

namespace Modules\Pharmacy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Medicines were sold at the pharmacy counter (manifest event "pharmacy.sale_made"):
 * the POS sale and the pharmacy's record of it. Ids and amounts only, never a patient's name.
 */
class MedicinesSold
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $linkId,
        public string $posSaleId,
        public int $totalMinor,
        public string $currency,
        public bool $hasControlled,
        public string $soldOn,
    ) {}

    public function name(): string
    {
        return 'pharmacy.sale_made';
    }
}
