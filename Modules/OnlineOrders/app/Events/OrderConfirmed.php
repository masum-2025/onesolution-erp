<?php

namespace Modules\OnlineOrders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An online order was confirmed and holds its stock (manifest event
 * "online_orders.confirmed"). Ids and amounts only, no personal data: a
 * channel (Facebook selling) tells its customer.
 */
class OrderConfirmed
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $orderId,
        public string $channel,
        public ?string $channelRef,
        public int $totalMinor,
        public string $currency,
    ) {}

    public function name(): string
    {
        return 'online_orders.confirmed';
    }
}
