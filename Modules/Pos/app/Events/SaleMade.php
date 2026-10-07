<?php

namespace Modules\Pos\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sale or return was made at a counter (manifest event "pos.sale_made").
 * Ids and amounts only, no personal data: CRM adds it to the customer's
 * purchases and points when the sale has a customer.
 */
class SaleMade
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $saleId,
        /** sale | return */
        public string $kind,
        public ?string $customerId,
        public int $totalMinor,
        public string $currency,
        public string $soldOn,
    ) {}

    public function name(): string
    {
        return 'pos.sale_made';
    }
}
