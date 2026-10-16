<?php

namespace Modules\FactoryErp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Crm\Services\Organizations;
use Modules\FactoryErp\Http\Controllers\Concerns\FindsFactory;
use Modules\FactoryErp\Http\FactoryPresenter;
use Modules\FactoryErp\Http\Requests\OrderRequest;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;
use Modules\FactoryErp\Models\Style;
use Modules\FactoryErp\Services\Floor;
use Modules\FactoryErp\Services\Orders;
use Modules\FactoryErp\Services\Requirements;

/**
 * Buyers' orders of the unit and the units below it: read with
 * factory_erp.view, made, changed and moved on with factory_erp.merchandise;
 * what an order needs from the store against what is there.
 */
class OrderController extends Controller
{
    use FindsFactory;

    public function __construct(
        private Floor $floor,
        private Orders $orders,
        private Requirements $requirements,
        private Organizations $buyers,
        private FactoryPresenter $presenter,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Order::STATUSES)], 'buyer_id' => ['nullable', 'string', 'size:26'], 'style_id' => ['nullable', 'string', 'size:26'],
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->floor->query(Order::class, $company)->whereIn('unit_id', $this->floor->subtreeIds($unit))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['buyer_id'] ?? null, fn ($query, $id) => $query->where('buyer_id', $id))
            ->when($filters['style_id'] ?? null, fn ($query, $id) => $query->where('style_id', $id))
            ->when($needle !== '', fn ($query) => $query->where(fn ($query) => $query->where('number', 'like', '%'.addcslashes($needle, '%_\\').'%')->orWhere('po_number', 'like', '%'.addcslashes($needle, '%_\\').'%')))
            ->orderBy('ship_on')->orderBy('number')->paginate(PerPage::from($request, 25));
        $orders = collect($page->items());
        $pieces = $this->floor->query(OrderLine::class, $company)->whereIn('order_id', $orders->pluck('id'))->get(['order_id', 'quantity'])->groupBy('order_id')->map(fn ($lines) => (int) $lines->sum('quantity'));
        $styles = $this->floor->query(Style::class, $company)->whereKey($orders->pluck('style_id')->unique())->get(['id', 'code', 'name'])->keyBy('id');

        return response()->json([
            'data' => $orders->map(fn (Order $order) => $this->presenter->order($order, extra: [
                'pieces' => (int) ($pieces[$order->getKey()] ?? 0), 'value_minor' => (int) ($pieces[$order->getKey()] ?? 0) * (int) $order->unit_price_minor,
            ]))->values(),
            'meta' => [
                'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'buyers' => (object) $this->buyers->many($company, $orders->pluck('buyer_id')->unique()->values()->all()),
                'styles' => (object) $styles->map(fn (Style $style) => ['code' => $style->code, 'name' => $style->name])->all(),
            ],
        ]);
    }

    public function show(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);

        return $this->answer($company, $this->orderIn($unit, $company, $order));
    }

    public function requirements(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);

        return response()->json(['data' => $this->requirements->of($company, $this->orderIn($unit, $company, $order))]);
    }

    public function store(OrderRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.merchandise', $unit);
        $made = $this->orders->save($company, $unit, null, $request->validated(), $request->user());

        return $this->answer($company, $made, $made->wasRecentlyCreated ? 201 : 200);
    }

    public function update(OrderRequest $request, string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->orderIn($unit, $company, $order);
        Gate::authorize('factory_erp.merchandise', $unit);

        return $this->answer($company, $this->orders->save($company, $unit, $found, $request->validated(), $request->user()));
    }

    public function confirm(Request $request, string $organization, string $order): JsonResponse
    {
        return $this->move($request, $organization, $order, 'confirm');
    }

    public function start(Request $request, string $organization, string $order): JsonResponse
    {
        return $this->move($request, $organization, $order, 'start');
    }

    public function ship(Request $request, string $organization, string $order): JsonResponse
    {
        return $this->move($request, $organization, $order, 'ship');
    }

    public function cancel(Request $request, string $organization, string $order): JsonResponse
    {
        return $this->move($request, $organization, $order, 'cancel');
    }

    /** Confirm, start production, ship or cancel (with a reason). */
    private function move(Request $request, string $organization, string $order, string $step): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->orderIn($unit, $company, $order);
        Gate::authorize('factory_erp.merchandise', $unit);
        $data = $request->validate([
            'base_version' => ['required', 'integer', 'min:1'],
            'reason' => [$step === 'cancel' ? 'required' : 'prohibited', 'string', 'min:3', 'max:300'],
        ]);

        return $this->answer($company, $this->orders->move($company, $found, $step, (int) $data['base_version'], isset($data['reason']) ? trim($data['reason']) : null, $request->user()));
    }

    private function answer($company, Order $order, int $status = 200): JsonResponse
    {
        $style = $this->floor->query(Style::class, $company)->whereKey($order->style_id)->first();

        return response()->json([
            'data' => $this->presenter->order($order, $this->orders->lines($company, $order)),
            'meta' => [
                'buyer' => $this->buyers->many($company, [$order->buyer_id])[$order->buyer_id] ?? null,
                'style' => $style === null ? null : $this->presenter->style($style),
            ],
        ], $status);
    }
}
