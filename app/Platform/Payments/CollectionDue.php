<?php

namespace App\Platform\Payments;

/**
 * What a customer owes for one record, as the owning module says: integer
 * minor units in a currency, never a float.
 */
final readonly class CollectionDue
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
    ) {}
}
