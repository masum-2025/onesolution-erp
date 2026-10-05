<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Http\Controllers\Concerns\FindsInventory;
use Modules\Inventory\Http\InventoryPresenter;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\BatchStock;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Move;
use Modules\Inventory\Services\Inventories;

/**
 * What is in stock where (inventory.view at the unit in the address, its
 * warehouses and those below): balances, one item with its warehouses,
 * batches and recent moves, a barcode or SKU lookup, and batches expiring.
 */
class StockController extends Controller
{
    use FindsInventory;

    public function __construct(private Inventories $inventories, private InventoryPresenter $presenter, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    /** Balances (?warehouse_id, ?low=1 for items at or near their reorder level). */
    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);
        $filters = $request->validate(['warehouse_id' => ['nullable', 'string', 'max:26'], 'low' => ['nullable', 'boolean']]);
        $warehouses = isset($filters['warehouse_id']) ? [$this->warehouseIn($unit, $company, $filters['warehouse_id'])->getKey()] : $this->warehouseIds($unit, $company);

        $balances = $this->inventories->query(Balance::class, $company)->whereIn('warehouse_id', $warehouses)->get();
        if (! empty($filters['low'])) {
            $items = $this->inventories->query(Item::class, $company)->whereIn('id', $balances->pluck('item_id')->unique()->all())->whereNotNull('reorder_level_milli')->get()->keyBy('id');
            $percent = (int) $this->rules->get('inventory.low_stock_alert_percent', $this->contexts->forOrganization($unit));
            $balances = $balances->filter(fn (Balance $balance) => isset($items[$balance->item_id])
                && $balance->quantity_milli * 100 <= $items[$balance->item_id]->reorder_level_milli * (100 + $percent));
        }

        return response()->json([
            'data' => $balances->map(fn (Balance $balance) => $this->presenter->balance($balance))->values(),
            'meta' => ['currency' => $this->inventories->currency($company), 'value_minor' => (int) $balances->sum('value_minor')],
        ]);
    }

    /** One item: stock per warehouse, batches with what each holds, the last moves. */
    public function item(string $organization, string $item): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);
        $found = $this->found(Item::class, $company, $item, 'item');
        $warehouses = $this->warehouseIds($unit, $company);
        $balances = $this->inventories->query(Balance::class, $company)->where('item_id', $found->getKey())->whereIn('warehouse_id', $warehouses)->get();
        $batches = $this->inventories->query(Batch::class, $company)->where('item_id', $found->getKey())->orderBy('expires_on')->get();
        $held = $this->inventories->query(BatchStock::class, $company)->whereIn('batch_id', $batches->pluck('id')->all())->whereIn('warehouse_id', $warehouses)
            ->get()->groupBy('batch_id')->map(fn ($rows) => (int) $rows->sum('quantity_milli'));

        return response()->json(['data' => [
            ...$this->presenter->item($found),
            'balances' => $balances->map(fn (Balance $balance) => $this->presenter->balance($balance))->values(),
            'batches' => $batches->map(fn (Batch $batch) => $this->presenter->batch($batch, $held[$batch->getKey()] ?? 0))->filter(fn ($batch) => $batch['quantity_milli'] !== 0)->values(),
            'moves' => $this->inventories->query(Move::class, $company)->where('item_id', $found->getKey())->whereIn('warehouse_id', $warehouses)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()->map(fn (Move $move) => $this->presenter->move($move))->values(),
        ], 'meta' => ['currency' => $this->inventories->currency($company)]]);
    }

    /** An item by its exact barcode or SKU (scanning). */
    public function lookup(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:64']])['code']);
        $found = $this->inventories->query(Item::class, $company)->where(fn ($query) => $query->where('barcode', $code)->orWhere('sku', $code))->first()
            ?? throw InventoryException::notFound('item');

        return response()->json(['data' => $this->presenter->item($found)]);
    }

    /** Moves (?item_id, ?warehouse_id, ?from, ?to), newest first. */
    public function moves(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);
        $filters = $request->validate(['item_id' => ['nullable', 'string', 'max:26'], 'warehouse_id' => ['nullable', 'string', 'max:26'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $warehouses = isset($filters['warehouse_id']) ? [$this->warehouseIn($unit, $company, $filters['warehouse_id'])->getKey()] : $this->warehouseIds($unit, $company);
        $query = $this->inventories->query(Move::class, $company)->whereIn('warehouse_id', $warehouses)->orderByDesc('moved_on')->orderByDesc('id')->limit(300);
        foreach (['item_id' => '=', 'from' => '>=', 'to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->where($key === 'item_id' ? 'item_id' : 'moved_on', $operator, $filters[$key]);
            }
        }

        return response()->json(['data' => $query->get()->map(fn (Move $move) => $this->presenter->move($move))->values(), 'meta' => ['currency' => $this->inventories->currency($company)]]);
    }

    /** Batches still held that expire within the rule's days (or have expired). */
    public function expiring(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);
        $days = (int) $this->rules->get('inventory.expiry_alert_days', $this->contexts->forOrganization($unit));
        $held = $this->inventories->query(BatchStock::class, $company)->whereIn('warehouse_id', $this->warehouseIds($unit, $company))->where('quantity_milli', '>', 0)->get();
        $batches = $this->inventories->query(Batch::class, $company)->whereIn('id', $held->pluck('batch_id')->unique()->all())
            ->whereNotNull('expires_on')->where('expires_on', '<=', CarbonImmutable::now()->addDays($days)->toDateString())->orderBy('expires_on')->get();
        $quantities = $held->groupBy('batch_id')->map(fn ($rows) => (int) $rows->sum('quantity_milli'));

        return response()->json(['data' => $batches->map(fn (Batch $batch) => $this->presenter->batch($batch, $quantities[$batch->getKey()] ?? 0))->values(), 'meta' => ['days' => $days]]);
    }
}
