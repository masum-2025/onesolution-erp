<?php

namespace Modules\OnlineOrders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Services\Stock;
use Modules\OnlineOrders\Exceptions\OnlineOrdersException;
use Modules\OnlineOrders\Http\OrdersPresenter;
use Modules\OnlineOrders\Http\Requests\OrderRequest;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Models\OrderEvent;
use Modules\OnlineOrders\Models\OrderLine;
use Modules\OnlineOrders\Models\OrderPayment;
use Modules\OnlineOrders\Services\Couriers;
use Modules\OnlineOrders\Services\OrderBook;
use Modules\OnlineOrders\Services\Orders;

/**
 * Online orders of the unit in the address and below: read
 * (online_orders.view or .manage), taken and moved on (online_orders.manage
 * at the order's branch), taken back or cancelled once shipped
 * (online_orders.supervise, checked again in Orders). A new order belongs
 * to the unit in the address (a company or a branch, never from the body).
 */
class OrderController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private OrderBook $book, private Orders $orders, private OrdersPresenter $presenter, private Stock $stock) {}

    /** Orders (?status, ?channel, ?search: a number or a mobile number), newest first. */
    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->mayRead($unit);
        $filters = $request->validate(['status' => ['nullable', 'in:'.implode(',', Order::STATUSES)], 'channel' => ['nullable', 'in:'.implode(',', Order::CHANNELS)], 'search' => ['nullable', 'string', 'max:40']]);
        $query = $this->book->query(Order::class, $company)->whereIn('unit_id', $this->book->subtreeIds($unit))->orderByDesc('created_at')->orderByDesc('id')->limit(300);
        foreach (['status', 'channel'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $phone = preg_match('/[A-Za-z]/', $search) ? null : PhoneNumber::normalize($search, $this->book->country($company));
            $query->where(fn ($where) => $phone !== null ? $where->where('customer_phone', $phone)->orWhere('number', $search) : $where->where('number', $search));
        }

        return response()->json(['data' => $query->get()->map(fn (Order $order) => $this->presenter->order($order))->values()]);
    }

    /** What the order screen needs: warehouses, delivery zones and the selling rules of the unit. */
    public function options(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->mayRead($unit);
        $context = app(RuleContextFactory::class)->forOrganization($unit);
        $rules = app(RuleResolver::class);

        return response()->json(['data' => [
            'warehouses' => $this->stock->warehouses($company),
            'zones' => $this->orders->zones($unit),
            'cod_allowed' => (bool) $rules->get('online_orders.cod_allowed', $context),
            'max_discount_percent' => (string) $rules->get('online_orders.max_discount_percent', $context),
            'currency' => $this->book->currency($company),
            'can_order' => $unit->type !== OrganizationType::Group && Gate::allows('online_orders.manage', $unit),
            // SC-5b: the company's courier accounts that can take a parcel now (names only).
            'couriers' => app(Couriers::class)->accounts($company)->filter(fn ($courier) => $courier->is_active)
                ->map(fn ($courier) => ['id' => $courier->getKey(), 'name' => $courier->name, 'driver' => $courier->driver])->values()->all(),
        ]]);
    }

    /** Items to put on an order (?warehouse_id, ?search): price and what the warehouse can still give. */
    public function items(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        abort_unless(Gate::allows('online_orders.manage', $unit), 403);
        $filters = $request->validate(['warehouse_id' => ['required', 'string', 'max:26'], 'search' => ['nullable', 'string', 'max:80']]);
        if ($this->stock->warehouse($company, $filters['warehouse_id']) === null) {
            throw OnlineOrdersException::warehouseUnusable();
        }
        $items = array_values(array_filter($this->stock->items($company, null, $filters['search'] ?? null, 50), fn (array $item) => $item['sale_price_minor'] !== null));
        $available = $this->orders->available($company, $filters['warehouse_id'], array_column($items, 'id'));

        return response()->json(['data' => array_map(fn (array $item) => [
            'id' => $item['id'], 'sku' => $item['sku'], 'name' => $item['name'], 'unit_id' => $item['unit_id'], 'sale_price_minor' => $item['sale_price_minor'],
            'keeps_stock' => $item['kind'] === 'stock', 'available_milli' => $item['kind'] === 'stock' ? $available[$item['id']] ?? 0 : null,
        ], $items)]);
    }

    public function store(OrderRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw OnlineOrdersException::notCompanyUnit();
        }
        Gate::authorize('online_orders.manage', $unit);

        return response()->json(['data' => $this->full($company, $this->orders->place($company, $unit, $request->validated(), $request->user()))], 201);
    }

    public function show(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->mayRead($unit);

        return response()->json(['data' => $this->full($company, $this->orderIn($unit, $company, $order))]);
    }

    public function update(OrderRequest $request, string $organization, string $order): JsonResponse
    {
        [$found, $company] = $this->managed($organization, $order);

        return response()->json(['data' => $this->full($company, $this->orders->revise($company, $found, (int) $request->validated('base_version'), $request->safe()->except('base_version'), $request->user()))]);
    }

    /** SC-5a: a payment link for the customer (staff copy it into a chat or SMS). */
    public function payLink(string $organization, string $order): JsonResponse
    {
        [$found, $company] = $this->managed($organization, $order);
        $link = $this->orders->payLink($company, $found->getKey()) ?? throw OnlineOrdersException::noPayLink();
        app(AuditLogger::class)->record('online_orders.pay_link_made', $found, new: ['number' => $found->number, 'amount_minor' => $link['amount_minor']], actor: request()->user(), organizationId: $company->getKey());

        return response()->json(['data' => $link]);
    }

    /** A step: confirm, pack, ship, deliver, cancel or return. */
    public function step(OrderRequest $request, string $organization, string $order, string $step): JsonResponse
    {
        [$found, $company] = $this->managed($organization, $order);
        $version = (int) $request->validated('base_version');
        $data = $request->safe()->except('base_version');
        $user = $request->user();
        $done = match ($step) {
            'confirm' => $this->orders->confirm($company, $found, $version, $user),
            'pack' => $this->orders->pack($company, $found, $version, $user),
            'ship' => $this->orders->ship($company, $found, $version, $data, $user),
            'deliver' => $this->orders->deliver($company, $found, $version, $user),
            'cancel' => $this->orders->cancel($company, $found, $version, $data, $user),
            'return' => $this->orders->takeBack($company, $found, $version, $data, $user),
        };

        return response()->json(['data' => $this->full($company, $done)]);
    }

    /** SC-5b: book with one of the company's courier accounts and send the order out. */
    public function book(OrderRequest $request, string $organization, string $order): JsonResponse
    {
        [$found, $company] = $this->managed($organization, $order);
        $booked = app(Couriers::class)->book($company, $found, (int) $request->validated('base_version'), (string) $request->validated('courier_id'), $request->user());

        return response()->json(['data' => $this->full($company, $booked)]);
    }

    /** SC-5b: ask the courier where the parcel stands now. */
    public function courierStatus(OrderRequest $request, string $organization, string $order): JsonResponse
    {
        [$found, $company] = $this->managed($organization, $order);

        return response()->json(['data' => $this->full($company, app(Couriers::class)->refresh($company, $found, $request->user()))]);
    }

    public function pay(OrderRequest $request, string $organization, string $order): JsonResponse
    {
        [$found, $company] = $this->managed($organization, $order);

        return response()->json(['data' => $this->full($company, $this->orders->pay($company, $found, $request->validated(), $request->user()))], 201);
    }

    // ---------------------------------------------------------------- inside

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, $this->book->companyOf($unit)];
    }

    private function mayRead(Organization $unit): void
    {
        abort_unless(Gate::any(['online_orders.view', 'online_orders.manage'], $unit), 403);
    }

    /**
     * The order (of the unit in the address or below) and its company, for one allowed to manage at the order's branch.
     *
     * @return array{0: Order, 1: Organization}
     */
    private function managed(string $organization, string $order): array
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->orderIn($unit, $company, $order);
        Gate::authorize('online_orders.manage', Organization::query()->findOrFail($found->unit_id));

        return [$found, $company];
    }

    private function orderIn(Organization $unit, Organization $company, string $id): Order
    {
        $order = $this->book->query(Order::class, $company)->whereKey($id)->first();
        if ($order === null || ! in_array($order->unit_id, $this->book->subtreeIds($unit), true)) {
            throw OnlineOrdersException::notFound('order');
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function full(Organization $company, Order $order): array
    {
        $id = $order->getKey();

        return $this->presenter->order($order,
            $this->book->query(OrderLine::class, $company)->where('order_id', $id)->orderBy('line_no')->get(),
            $this->book->query(OrderPayment::class, $company)->where('order_id', $id)->orderBy('created_at')->orderBy('id')->get(),
            $this->book->query(OrderEvent::class, $company)->where('order_id', $id)->orderBy('created_at')->orderBy('id')->get(),
            ['company' => $company->displayName()]);
    }
}
