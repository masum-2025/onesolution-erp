<?php

namespace Modules\OnlineOrders\Couriers;

/** What the courier is asked to carry: the order's number, the customer, and the cash to collect (minor units). */
final readonly class Parcel
{
    public function __construct(
        public string $invoice,
        public string $name,
        public string $phone,
        public string $address,
        public int $codMinor,
        public string $currency,
        public ?string $note = null,
    ) {}
}
