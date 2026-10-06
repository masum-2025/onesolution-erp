<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Http\Controllers\Concerns\FindsInventory;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Services\Inventories;
use Modules\Inventory\Services\StockReports;

/**
 * Stock reports (inventory.view at the unit in the address) for its
 * warehouses and those below, or one of them: valuation on a day, what to
 * reorder, slow movers, and one item's ledger. The screens turn these into
 * CSV; the route is rate limited.
 */
class ReportController extends Controller
{
    use FindsInventory;

    public function __construct(private Inventories $inventories, private StockReports $reports) {}

    public function show(Request $request, string $organization, string $report): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);
        $today = $this->inventories->today($company);
        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'string', 'max:26'],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'days' => ['nullable', 'integer', 'between:7,730'],
            'item_id' => [$report === 'ledger' ? 'required' : 'nullable', 'string', 'max:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $warehouses = empty($filters['warehouse_id']) ? $this->warehouseIds($unit, $company) : [(string) $this->warehouseIn($unit, $company, $filters['warehouse_id'])->getKey()];
        $meta = ['currency' => $this->inventories->currency($company), 'today' => $today->toDateString()];

        return match ($report) {
            'valuation' => response()->json(['data' => $this->reports->valuation($company, $warehouses, $asOf = $filters['as_of'] ?? $today->toDateString()), 'meta' => [...$meta, 'as_of' => $asOf]]),
            'reorder' => response()->json(['data' => $this->reports->reorder($company, $warehouses), 'meta' => $meta]),
            'slow' => response()->json(['data' => $this->reports->slow($company, $warehouses, $days = (int) ($filters['days'] ?? 60), $today), 'meta' => [...$meta, 'days' => $days]]),
            'ledger' => $this->ledger($company, $warehouses, $filters, $today, $meta),
            default => throw InventoryException::notFound('report'),
        };
    }

    /**
     * @param  list<string>  $warehouses
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $meta
     */
    private function ledger($company, array $warehouses, array $filters, CarbonImmutable $today, array $meta): JsonResponse
    {
        /** @var Item $item */
        $item = $this->found(Item::class, $company, $filters['item_id'], 'item');
        $from = $filters['from'] ?? $today->subDays(30)->toDateString();
        $to = $filters['to'] ?? $today->toDateString();
        $ledger = $this->reports->ledger($company, $item, $warehouses, $from, $to);
        if ($ledger['too_many']) {
            throw InventoryException::rangeTooLong(StockReports::MAX_LEDGER);
        }

        return response()->json(['data' => $ledger['rows'], 'meta' => [...$meta, 'from' => $from, 'to' => $to, 'item_id' => $item->getKey(),
            'opening' => $ledger['opening'], 'closing' => $ledger['closing']]]);
    }
}
