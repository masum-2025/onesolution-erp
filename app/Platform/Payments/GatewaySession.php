<?php

namespace App\Platform\Payments;

/**
 * A hosted payment page the gateway opened for one payment.
 */
final readonly class GatewaySession
{
    public function __construct(
        public string $redirectUrl,
        public ?string $reference = null,
    ) {}
}
