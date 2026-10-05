<?php

namespace Modules\Inventory\Services;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Move;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;

/**
 * Inventory's public service for other modules (POS, later Factory): items
 * to sell, stock on hand, and stock going out (a sale) or coming back (a
 * return) for another module's record. Nothing is posted to the books here:
 * the calling module posts its own entry with the cost this returns.
 *
 * Idempotent: the same record (module, type, id) moves stock once; asking
 * again returns what was moved.
 */
class Stock
{
    public function __construct(private Inventories $inventories, private StockLedger $ledger) {}

    /**
     * Active items for selling: sku, barcode, name, unit, price, tax code, whether stock is kept.
     *
     * @param  list<string>|null  $ids
     * @return list<array<string, mixed>>
     */
    public function items(Organization $company, ?array $ids = null, ?string $search = null, int $limit = 500): array
    {
        $query = $this->inventories->query(Item::class, $company)->where('is_active', true)->orderBy('sku')->limit($limit);
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
        $items = $query->get();
        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $items = $items->filter(fn (Item $item) => str_contains(mb_strtolower($item->sku), $needle) || $item->barcode === trim($search)
                || collect($item->texts('name'))->contains(fn ($text) => str_contains(mb_strtolower((string) $text), $needle)));
        }

        return $items->map(fn (Item $item) => [
            'id' => $item->getKey(), 'sku' => $item->sku, 'barcode' => $item->barcode, 'name' => $item->name, 'names' => $item->texts('name'),
            'unit_id' => $item->unit_id, 'kind' => $item->kind, 'track_batches' => $item->track_batches, 'sale_price_minor' => $item->sale_price_minor,
            'tax_code_id' => $item->tax_code_id, 'category_id' => $item->category_id, 'version' => $item->version,
        ])->values()->all();
    }

    /**
     * A warehouse of the company: its branch and whether it is in use; null when not the company's.
     *
     * @return array{id: string, unit_id: string, code: string, name: string, is_active: bool}|null
     */
    public function warehouse(Organization $company, string $id): ?array
    {
        $warehouse = $this->inventories->query(Warehouse::class, $company)->whereKey($id)->first();

        return $warehouse === null ? null : ['id' => $warehouse->getKey(), 'unit_id' => $warehouse->unit_id, 'code' => $warehouse->code, 'name' => $warehouse->name, 'is_active' => $warehouse->is_active];
    }

    /**
     * Units of measure: name and how many decimals a quantity may have.
     *
     * @return array<string, array{code: string, name: string, decimals: int}>
     */
    public function units(Organization $company): array
    {
        return $this->inventories->query(Unit::class, $company)->get()
            ->mapWithKeys(fn (Unit $unit) => [$unit->getKey() => ['code' => $unit->code, 'name' => $unit->name, 'decimals' => $unit->decimals]])->all();
    }

    /**
     * Quantity on hand per item in a warehouse.
     *
     * @param  list<string>  $itemIds
     * @return array<string, int>
     */
    public function onHand(Organization $company, string $warehouseId, array $itemIds): array
    {
        return $this->inventories->query(Balance::class, $company)->where('warehouse_id', $warehouseId)->whereIn('item_id', $itemIds)
            ->pluck('quantity_milli', 'item_id')->map(fn ($quantity) => (int) $quantity)->all();
    }

    /**
     * Stock leaves (quantities positive) or comes back (negative quantities) for another module's record.
     * Lines of non-stock items are passed over (cost 0). Returns the cost per line and in all.
     *
     * A line coming back may give the cost of one (what it left at); $force
     * lets stock go below zero for something already done (an offline sale).
     *
     * @param  list<array{item_id: string, quantity_milli: int, batch_number?: string|null, unit_cost_minor?: int|null}>  $lines
     * @param  array{module: string, type: string, id: string, actor_id?: string|null}  $source
     * @return array{lines: list<int>, cost_minor: int, moved: bool}
     */
    public function take(Organization $company, string $warehouseId, array $lines, array $source, CarbonImmutable $on, bool $force = false): array
    {
        return $this->inventories->transaction($company, function () use ($company, $warehouseId, $lines, $source, $on, $force) {
            $done = $this->inventories->query(Move::class, $company)->where('source_module', $source['module'])->where('source_type', $source['type'])->where('source_id', $source['id'])->get();
            if ($done->isNotEmpty()) {
                $total = -(int) $done->sum('value_minor');

                return ['lines' => [], 'cost_minor' => $total, 'moved' => false];
            }
            $warehouse = $this->inventories->query(Warehouse::class, $company)->whereKey($warehouseId)->first() ?? throw InventoryException::notFound('warehouse');
            $items = $this->inventories->query(Item::class, $company)->whereIn('id', array_column($lines, 'item_id'))->get()->keyBy('id');
            $costs = [];
            foreach ($lines as $line) {
                $item = $items[$line['item_id']] ?? throw InventoryException::notFound('item');
                if (! $item->keepsStock()) {
                    $costs[] = 0;

                    continue;
                }
                $quantity = (int) $line['quantity_milli'];
                $kind = $quantity >= 0 ? 'sale' : 'return';
                // A return comes back at the cost given (what it left at), else today's.
                $cost = $quantity < 0 ? ($line['unit_cost_minor'] ?? $this->ledger->unitCost($company, $item, $warehouse)) : null;
                $moves = $this->ledger->move($company, $item, $warehouse, $kind, -$quantity, $cost,
                    ['source_module' => $source['module'], 'source_type' => $source['type'], 'source_id' => $source['id'], 'actor_id' => $source['actor_id'] ?? null],
                    $on, ['number' => $line['batch_number'] ?? null], null, $force);
                $costs[] = -array_sum(array_map(fn (Move $move) => $move->value_minor, $moves));
            }

            return ['lines' => $costs, 'cost_minor' => array_sum($costs), 'moved' => true];
        });
    }
}
