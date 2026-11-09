<?php

namespace Modules\OnlineOrders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An online order left the warehouse (manifest event "online_orders.shipped").
 * Ids only, no personal data: a channel (Facebook selling) tells its
 * customer, with the courier and tracking from Orders::brief().
 */
class OrderShipped
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $orderId,
        public string $channel,
        public ?string $channelRef,
    ) {}

    public function name(): string
    {
        return 'online_orders.shipped';
    }
}
