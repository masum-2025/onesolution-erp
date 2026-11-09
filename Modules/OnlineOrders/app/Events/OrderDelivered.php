<?php

namespace Modules\OnlineOrders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An online order reached its customer (manifest event
 * "online_orders.delivered"). Ids and amounts only, no personal data.
 */
class OrderDelivered
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $orderId,
        public string $channel,
        public ?string $channelRef,
        public ?string $customerId,
        public int $totalMinor,
        public string $currency,
        public string $deliveredOn,
    ) {}

    public function name(): string
    {
        return 'online_orders.delivered';
    }
}
