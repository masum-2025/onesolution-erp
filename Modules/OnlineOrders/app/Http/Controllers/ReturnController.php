<?php

namespace Modules\OnlineOrders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\OnlineOrders\Exceptions\OnlineOrdersException;
use Modules\OnlineOrders\Http\OrdersPresenter;
use Modules\OnlineOrders\Http\Requests\ReceiveBackRequest;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Models\OrderLine;
use Modules\OnlineOrders\Services\OrderBook;
use Modules\OnlineOrders\Services\Orders;

/**
 * SC-7: parcels the courier could not deliver and is bringing back, and
 * taking one back into the warehouse, with online_orders.receive_returns
 * (at the unit in the address for the list, at the order's branch to receive).
 */
class ReturnController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private OrderBook $book, private Orders $orders, private OrdersPresenter $presenter) {}

    /** Parcels coming back to the unit and below, oldest first: what is in each, and whether it is late. */
    public function index(string $organization): JsonResponse
    {
        $unit = $this->findVisible($organization);
        abort_unless(Gate::allows('online_orders.receive_returns', $unit), 403);
        $company = $this->book->companyOf($unit);
        $orders = $this->orders->comingBack($company, $this->book->subtreeIds($unit));
        $lines = $this->book->query(OrderLine::class, $company)->whereIn('order_id', $orders->modelKeys())->orderBy('line_no')->get()->groupBy('order_id');
        $days = [];
        $now = CarbonImmutable::now();

        return response()->json(['data' => $orders->map(function (Order $order) use ($lines, &$days, $now) {
            $limit = $days[$order->unit_id] ??= $this->orders->overdueDays($order->unit_id);

            return [
                ...$this->presenter->order($order),
                'days_on_way' => (int) $order->courier_returning_at->diffInDays($now),
                'overdue' => $order->courier_returning_at->addDays($limit)->isPast(),
                'items' => ($lines[$order->getKey()] ?? collect())->map(fn (OrderLine $line) => ['name' => $line->name, 'quantity_milli' => $line->quantity_milli])->values()->all(),
            ];
        })->values()->all()]);
    }

    public function receive(ReceiveBackRequest $request, string $organization, string $order): JsonResponse
    {
        $unit = $this->findVisible($organization);
        $company = $this->book->companyOf($unit);
        $found = $this->book->query(Order::class, $company)->whereKey($order)->first();
        if ($found === null || ! in_array($found->unit_id, $this->book->subtreeIds($unit), true)) {
            throw OnlineOrdersException::notFound('order');
        }
        Gate::authorize('online_orders.receive_returns', Organization::query()->findOrFail($found->unit_id));
        $done = $this->orders->receiveBack($company, $found, (int) $request->validated('base_version'), $request->safe()->except('base_version'), $request->user());

        return response()->json(['data' => $this->presenter->order($done)]);
    }
}
