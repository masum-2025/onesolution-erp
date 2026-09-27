<?php

namespace App\Platform\Payments;

/**
 * Who pays, as the gateway's form needs it (gateways ask for a name and a
 * way to reach the payer). Only what the person gave us, nothing more.
 */
final readonly class PaymentCustomer
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?string $phone,
        public string $countryCode,
    ) {}
}
