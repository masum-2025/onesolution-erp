<?php

namespace Modules\OnlineOrders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The customer paid for an online order through a payment link (SC-5a,
 * manifest event "online_orders.paid"). Ids and amounts only: a channel
 * (Facebook selling) thanks its customer.
 */
class OrderPaid
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $orderId,
        public string $channel,
        public ?string $channelRef,
        public int $amountMinor,
        public string $currency,
    ) {}

    public function name(): string
    {
        return 'online_orders.paid';
    }
}
