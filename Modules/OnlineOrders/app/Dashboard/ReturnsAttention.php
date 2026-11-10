<?php

namespace Modules\OnlineOrders\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Modules\OnlineOrders\Exceptions\OnlineOrdersException;
use Modules\OnlineOrders\Services\OrderBook;
use Modules\OnlineOrders\Services\Orders;

/** SC-7, in the bell: parcels the courier is bringing back (warn once one is late), for people who receive them. */
final class ReturnsAttention implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($context->user() === null || ! Gate::allows('online_orders.receive_returns', $unit)) {
            return [];
        }
        $book = app(OrderBook::class);
        $orders = app(Orders::class);
        try {
            $coming = $orders->comingBack($book->companyOf($unit), $book->subtreeIds($unit));
        } catch (OnlineOrdersException) {
            return [];
        }
        if ($coming->isEmpty()) {
            return [];
        }
        $days = [];
        $late = $coming->contains(fn ($order) => $order->courier_returning_at->addDays($days[$order->unit_id] ??= $orders->overdueDays($order->unit_id))->isPast());

        return [new AttentionItem('online_orders.returning', __('online_orders::orders.attention_returning'), $coming->count(), '/online-orders/returns', $late ? 'warn' : 'info')];
    }
}
