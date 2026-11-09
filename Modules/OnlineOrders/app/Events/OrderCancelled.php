<?php

namespace Modules\OnlineOrders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An online order was cancelled or taken back (manifest event
 * "online_orders.cancelled"). Ids only, no personal data.
 */
class OrderCancelled
{
    use Dispatchable;

    public function __construct(
        public string $companyId,
        public string $orderId,
        public string $channel,
        public ?string $channelRef,
        /** cancelled | returned */
        public string $status,
    ) {}

    public function name(): string
    {
        return 'online_orders.cancelled';
    }
}
