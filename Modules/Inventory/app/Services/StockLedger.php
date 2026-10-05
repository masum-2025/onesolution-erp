<?php

namespace Modules\Inventory\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Inventory\Events\StockRanLow;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\BatchStock;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Layer;
use Modules\Inventory\Models\Move;
use Modules\Inventory\Models\Warehouse;

/**
 * The only way stock changes. Each call writes moves (append-only) and keeps
 * the item's balance in the warehouse, its receipt layers and its batch
 * quantities in step, inside the caller's transaction.
 *
 * - In (quantity above zero): valued at the unit cost given, or the current
 *   cost; becomes a layer.
 * - Out (beyond zero only by rule, or forced by a caller for something
 *   already done, e.g. an offline sale): valued by the balance's method, fixed when it started (rule
 *   inventory.valuation_method): weighted average (the balance's value
 *   share) or FIFO (oldest layers first). Taking all that is left takes all
 *   the value left. Beyond zero only where inventory.allow_negative_stock
 *   allows, at the last known cost.
 * - Batches: an item that tracks them needs a batch number coming in; going
 *   out without one takes the batch expiring first (FEFO), split as needed.
 *
 * Quantities are thousandths (quantity_milli); a unit cost is per whole unit.
 */
class StockLedger
{
    public function __construct(private Inventories $inventories, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    /**
     * @param  array{source_module: string, source_type: string, source_id: string, note?: string|null, actor_id?: string|null}  $source
     * @param  array{number?: string|null, expires_on?: string|null}  $batch
     * @param  int|null  $valueMinor  Coming in: the exact value (a transfer arriving at what it left at), instead of quantity times cost.
     * @return list<Move>
     */
    public function move(Organization $company, Item $item, Warehouse $warehouse, string $kind, int $quantityMilli, ?int $unitCostMinor, array $source, CarbonImmutable $on, array $batch = [], ?int $valueMinor = null, bool $force = false): array
    {
        if (! $item->keepsStock()) {
            throw InventoryException::notStockItem($item->sku);
        }
        if ($quantityMilli === 0) {
            return [];
        }
        $balance = $this->balance($company, $item, $warehouse);

        if ($quantityMilli > 0) {
            $batchId = $item->track_batches ? $this->batchFor($company, $item, $batch)->getKey() : null;

            return [$this->in($company, $item, $warehouse, $balance, $kind, $quantityMilli, $unitCostMinor, $source, $on, $batchId, $valueMinor)];
        }

        $wanted = -$quantityMilli;
        if ($balance->quantity_milli < $wanted && ! $force && ! $this->negativeAllowed($warehouse)) {
            throw InventoryException::insufficientStock($item->sku, $this->quantityText($balance->quantity_milli));
        }

        // Which batches it comes out of (FEFO unless one was named).
        $parts = [[null, $wanted]];
        if ($item->track_batches) {
            $parts = $this->batchesOut($company, $item, $warehouse, $wanted, $batch['number'] ?? null, $force);
        }

        $moves = [];
        foreach ($parts as [$batchId, $quantity]) {
            $moves[] = $this->out($company, $item, $warehouse, $balance, $kind, $quantity, $source, $on, $batchId);
        }

        return $moves;
    }

    /** What one unit costs now in the warehouse (the balance's average, or the last known cost). */
    public function unitCost(Organization $company, Item $item, Warehouse $warehouse): int
    {
        $balance = $this->inventories->query(Balance::class, $company)->where('item_id', $item->getKey())->where('warehouse_id', $warehouse->getKey())->first();
        if ($balance === null) {
            return 0;
        }

        return $balance->quantity_milli > 0 ? self::divide($balance->value_minor * 1000, $balance->quantity_milli) : $balance->last_unit_cost_minor;
    }

    /** "12.5" from 12500 thousandths (for messages). */
    public function quantityText(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);
        $fraction = rtrim(str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT), '0');

        return $sign.intdiv($milli, 1000).($fraction === '' ? '' : ".{$fraction}");
    }

    /** Quantity times a unit cost, half up (quantity in thousandths). */
    public static function value(int $quantityMilli, int $unitCostMinor): int
    {
        return self::divide($quantityMilli * $unitCostMinor, 1000);
    }

    private function in(Organization $company, Item $item, Warehouse $warehouse, Balance $balance, string $kind, int $quantity, ?int $unitCost, array $source, CarbonImmutable $on, ?string $batchId, ?int $exact = null): Move
    {
        $cost = $unitCost ?? ($balance->quantity_milli > 0 ? self::divide($balance->value_minor * 1000, $balance->quantity_milli) : $balance->last_unit_cost_minor);
        $value = $exact ?? self::value($quantity, $cost);
        $move = $this->write($company, $item, $warehouse, $kind, $quantity, $value, $source, $on, $batchId);

        $layer = new Layer;
        $layer->fill(['organization_id' => $company->getKey(), 'item_id' => $item->getKey(), 'warehouse_id' => $warehouse->getKey(), 'move_id' => $move->getKey(),
            'remaining_milli' => $quantity, 'remaining_value_minor' => $value])->save();
        if ($batchId !== null) {
            $this->batchStock($company, $batchId, $warehouse, $quantity);
        }

        $balance->quantity_milli += $quantity;
        $balance->value_minor += $value;
        if ($cost > 0) {
            $balance->last_unit_cost_minor = $cost;
        }
        $balance->version++;
        $balance->save();

        return $move;
    }

    private function out(Organization $company, Item $item, Warehouse $warehouse, Balance $balance, string $kind, int $quantity, array $source, CarbonImmutable $on, ?string $batchId): Move
    {
        $held = max(0, $balance->quantity_milli);
        $fromStock = min($quantity, $held);
        $beyond = $quantity - $fromStock;

        // Layers always give up the quantity, oldest first; under FIFO their value is the cost.
        $layerValue = 0;
        $left = $fromStock;
        foreach ($this->inventories->query(Layer::class, $company)->where('item_id', $item->getKey())->where('warehouse_id', $warehouse->getKey())
            ->where('remaining_milli', '>', 0)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get() as $layer) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $layer->remaining_milli);
            $share = $take === $layer->remaining_milli ? $layer->remaining_value_minor : self::divide($layer->remaining_value_minor * $take, $layer->remaining_milli);
            $layer->forceFill(['remaining_milli' => $layer->remaining_milli - $take, 'remaining_value_minor' => $layer->remaining_value_minor - $share])->save();
            $layerValue += $share;
            $left -= $take;
        }

        if ($fromStock === $held && $held > 0) {
            $value = $balance->value_minor;
        } elseif ($balance->method === 'FIFO') {
            $value = $layerValue;
        } else {
            $value = $held > 0 ? self::divide($balance->value_minor * $fromStock, $held) : 0;
        }
        $value += self::value($beyond, $balance->last_unit_cost_minor);

        $move = $this->write($company, $item, $warehouse, $kind, -$quantity, -$value, $source, $on, $batchId);
        if ($batchId !== null) {
            $this->batchStock($company, $batchId, $warehouse, -$quantity);
        }

        $before = $balance->quantity_milli;
        if ($held > 0) {
            $balance->last_unit_cost_minor = self::divide($balance->value_minor * 1000, $held);
        }
        $balance->quantity_milli -= $quantity;
        $balance->value_minor -= $value;
        $balance->version++;
        $balance->save();

        $level = $item->reorder_level_milli;
        if ($level !== null && $before > $level && $balance->quantity_milli <= $level) {
            StockRanLow::dispatch($company->getKey(), $item->getKey(), $warehouse->getKey(), $balance->quantity_milli);
        }

        return $move;
    }

    private function write(Organization $company, Item $item, Warehouse $warehouse, string $kind, int $quantity, int $value, array $source, CarbonImmutable $on, ?string $batchId): Move
    {
        $move = new Move;
        $move->fill([
            'organization_id' => $company->getKey(), 'item_id' => $item->getKey(), 'warehouse_id' => $warehouse->getKey(), 'batch_id' => $batchId,
            'kind' => $kind, 'quantity_milli' => $quantity, 'value_minor' => $value, 'moved_on' => $on->toDateString(),
            'source_module' => $source['source_module'], 'source_type' => $source['source_type'], 'source_id' => $source['source_id'],
            'note' => $source['note'] ?? null, 'created_by' => $source['actor_id'] ?? null,
        ])->save();

        return $move;
    }

    /** The item's balance in the warehouse, locked; a new one takes the company's valuation method. */
    private function balance(Organization $company, Item $item, Warehouse $warehouse): Balance
    {
        $balance = $this->inventories->query(Balance::class, $company)->where('item_id', $item->getKey())->where('warehouse_id', $warehouse->getKey())->lockForUpdate()->first();
        if ($balance !== null) {
            return $balance;
        }
        $balance = new Balance;
        $balance->fill([
            'organization_id' => $company->getKey(), 'item_id' => $item->getKey(), 'warehouse_id' => $warehouse->getKey(), 'quantity_milli' => 0, 'value_minor' => 0,
            'last_unit_cost_minor' => 0, 'method' => (string) $this->rules->get('inventory.valuation_method', $this->contexts->forOrganization($company)), 'version' => 0,
        ])->save();

        return $balance;
    }

    /** @param  array{number?: string|null, expires_on?: string|null}  $batch */
    private function batchFor(Organization $company, Item $item, array $batch): Batch
    {
        $number = trim((string) ($batch['number'] ?? ''));
        if ($number === '') {
            throw InventoryException::batchNeeded($item->sku);
        }
        $found = $this->inventories->query(Batch::class, $company)->where('item_id', $item->getKey())->where('number', $number)->first();
        if ($found !== null) {
            if ($found->expires_on === null && ! empty($batch['expires_on'])) {
                $found->forceFill(['expires_on' => $batch['expires_on']])->save();
            }

            return $found;
        }
        $found = new Batch;
        $found->fill(['organization_id' => $company->getKey(), 'item_id' => $item->getKey(), 'number' => $number, 'expires_on' => $batch['expires_on'] ?? null])->save();

        return $found;
    }

    /**
     * The batches a quantity comes out of: the one named, or those expiring first.
     *
     * @return list<array{0: string|null, 1: int}>
     */
    private function batchesOut(Organization $company, Item $item, Warehouse $warehouse, int $wanted, ?string $number, bool $force = false): array
    {
        if ($number !== null && trim($number) !== '') {
            $batch = $this->inventories->query(Batch::class, $company)->where('item_id', $item->getKey())->where('number', trim($number))->first()
                ?? throw InventoryException::notFound('batch');
            $held = (int) $this->inventories->query(BatchStock::class, $company)->where('batch_id', $batch->getKey())->where('warehouse_id', $warehouse->getKey())->value('quantity_milli');
            if ($held < $wanted && ! $force && ! $this->negativeAllowed($warehouse)) {
                throw InventoryException::insufficientStock("{$item->sku} / {$batch->number}", $this->quantityText($held));
            }

            return [[$batch->getKey(), $wanted]];
        }

        $stock = $this->inventories->query(BatchStock::class, $company)->where('warehouse_id', $warehouse->getKey())->where('quantity_milli', '>', 0)
            ->whereIn('batch_id', $this->inventories->query(Batch::class, $company)->where('item_id', $item->getKey())->select('id'))->lockForUpdate()->get();
        $batches = $this->inventories->query(Batch::class, $company)->whereIn('id', $stock->pluck('batch_id')->all())->get()->keyBy('id');
        // Expiring first; batches without an expiry last; then the oldest.
        $stock = $stock->sortBy(fn (BatchStock $row) => [$batches[$row->batch_id]->expires_on === null ? 1 : 0, $batches[$row->batch_id]->expires_on?->toDateString() ?? '', $batches[$row->batch_id]->created_at?->getTimestamp() ?? 0])->values();

        $parts = [];
        foreach ($stock as $row) {
            if ($wanted <= 0) {
                break;
            }
            $take = min($wanted, $row->quantity_milli);
            $parts[] = [$row->batch_id, $take];
            $wanted -= $take;
        }
        if ($wanted > 0) {
            // Beyond what the batches hold (negative stock allowed): without a batch.
            $parts[] = [null, $wanted];
        }

        return $parts;
    }

    private function batchStock(Organization $company, string $batchId, Warehouse $warehouse, int $change): void
    {
        $row = $this->inventories->query(BatchStock::class, $company)->where('batch_id', $batchId)->where('warehouse_id', $warehouse->getKey())->lockForUpdate()->first();
        if ($row === null) {
            $row = new BatchStock;
            $row->fill(['organization_id' => $company->getKey(), 'batch_id' => $batchId, 'warehouse_id' => $warehouse->getKey(), 'quantity_milli' => 0]);
        }
        $row->quantity_milli += $change;
        $row->save();
    }

    private function negativeAllowed(Warehouse $warehouse): bool
    {
        return (bool) $this->rules->get('inventory.allow_negative_stock', $this->contexts->forOrganization(Organization::query()->findOrFail($warehouse->unit_id)));
    }

    private static function divide(int $value, int $by): int
    {
        if ($by === 0) {
            return 0;
        }
        $half = intdiv(abs($by), 2);

        return $value >= 0 ? intdiv($value + $half, $by) : -intdiv(-$value + $half, $by);
    }
}
