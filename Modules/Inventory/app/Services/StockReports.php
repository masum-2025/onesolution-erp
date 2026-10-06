<?php

namespace Modules\Inventory\Services;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Move;

/**
 * Stock reports for the warehouses a reader sees. Totals are added up here,
 * not in SQL, so every database gives the same answer:
 *
 * - valuation: quantity and value per item and warehouse on a day (today's
 *   balances less the moves after that day);
 * - reorder: what is at or below its reorder level, and how much to order;
 * - slow: stock that has not gone out (sold, issued or sent on) for days;
 * - ledger: one item's moves between two days, with the running balance.
 */
class StockReports
{
    /** Kinds of move that take stock out to someone. */
    public const OUT = ['sale', 'issue', 'transfer_out'];

    /** Most moves one item ledger shows; a longer range is refused with advice to narrow it. */
    public const MAX_LEDGER = 3000;

    public function __construct(private Inventories $inventories) {}

    /**
     * @param  list<string>  $warehouses
     * @return list<array{item_id: string, warehouse_id: string, quantity_milli: int, value_minor: int}>
     */
    public function valuation(Organization $company, array $warehouses, string $asOf): array
    {
        $rows = [];
        foreach ($this->inventories->query(Balance::class, $company)->whereIn('warehouse_id', $warehouses)->get() as $balance) {
            $rows["{$balance->item_id}|{$balance->warehouse_id}"] = ['item_id' => $balance->item_id, 'warehouse_id' => $balance->warehouse_id,
                'quantity_milli' => (int) $balance->quantity_milli, 'value_minor' => (int) $balance->value_minor];
        }
        foreach ($this->inventories->query(Move::class, $company)->whereIn('warehouse_id', $warehouses)->where('moved_on', '>', $asOf)
            ->select(['item_id', 'warehouse_id', 'quantity_milli', 'value_minor'])->cursor() as $move) {
            $key = "{$move->item_id}|{$move->warehouse_id}";
            $rows[$key] ??= ['item_id' => $move->item_id, 'warehouse_id' => $move->warehouse_id, 'quantity_milli' => 0, 'value_minor' => 0];
            $rows[$key]['quantity_milli'] -= (int) $move->quantity_milli;
            $rows[$key]['value_minor'] -= (int) $move->value_minor;
        }

        return array_values(array_filter($rows, fn (array $row) => $row['quantity_milli'] !== 0 || $row['value_minor'] !== 0));
    }

    /**
     * @param  list<string>  $warehouses
     * @return list<array{item_id: string, warehouse_id: string, quantity_milli: int, reorder_level_milli: int, order_milli: int, unit_cost_minor: int}>
     */
    public function reorder(Organization $company, array $warehouses): array
    {
        $items = $this->inventories->query(Item::class, $company)->where('is_active', true)->where('kind', 'stock')->whereNotNull('reorder_level_milli')->get()->keyBy('id');
        $rows = [];
        foreach ($this->inventories->query(Balance::class, $company)->whereIn('warehouse_id', $warehouses)->whereIn('item_id', $items->keys()->all())->get() as $balance) {
            $item = $items[$balance->item_id];
            if ($balance->quantity_milli > $item->reorder_level_milli) {
                continue;
            }
            // The item's usual order, else enough to reach twice the level.
            $order = $item->reorder_quantity_milli ?? max(2 * $item->reorder_level_milli - $balance->quantity_milli, 0);
            $rows[] = ['item_id' => $item->getKey(), 'warehouse_id' => $balance->warehouse_id, 'quantity_milli' => (int) $balance->quantity_milli,
                'reorder_level_milli' => (int) $item->reorder_level_milli, 'order_milli' => (int) $order, 'unit_cost_minor' => self::unitCost($balance)];
        }
        // Emptiest first: the smallest share of its level.
        usort($rows, fn (array $a, array $b) => $a['quantity_milli'] * $b['reorder_level_milli'] <=> $b['quantity_milli'] * $a['reorder_level_milli']);

        return $rows;
    }

    /**
     * @param  list<string>  $warehouses
     * @return list<array{item_id: string, warehouse_id: string, quantity_milli: int, value_minor: int, last_out_on: string|null, idle_days: int|null}>
     */
    public function slow(Organization $company, array $warehouses, int $days, CarbonImmutable $today): array
    {
        $last = [];
        foreach ($this->inventories->query(Move::class, $company)->whereIn('warehouse_id', $warehouses)->whereIn('kind', self::OUT)
            ->select(['item_id', 'warehouse_id', 'moved_on'])->cursor() as $move) {
            $key = "{$move->item_id}|{$move->warehouse_id}";
            $on = $move->moved_on->toDateString();
            $last[$key] = max($last[$key] ?? $on, $on);
        }
        $since = $today->subDays($days)->toDateString();
        $rows = [];
        foreach ($this->inventories->query(Balance::class, $company)->whereIn('warehouse_id', $warehouses)->where('quantity_milli', '>', 0)->get() as $balance) {
            $out = $last["{$balance->item_id}|{$balance->warehouse_id}"] ?? null;
            if ($out !== null && $out > $since) {
                continue;
            }
            $rows[] = ['item_id' => $balance->item_id, 'warehouse_id' => $balance->warehouse_id, 'quantity_milli' => (int) $balance->quantity_milli,
                'value_minor' => (int) $balance->value_minor, 'last_out_on' => $out, 'idle_days' => $out === null ? null : (int) CarbonImmutable::parse($out)->diffInDays($today)];
        }
        usort($rows, fn (array $a, array $b) => $b['value_minor'] <=> $a['value_minor']);

        return $rows;
    }

    /**
     * @param  list<string>  $warehouses
     * @return array{opening: array{quantity_milli: int, value_minor: int}, rows: list<array<string, mixed>>, closing: array{quantity_milli: int, value_minor: int}, too_many: bool}
     */
    public function ledger(Organization $company, Item $item, array $warehouses, string $from, string $to): array
    {
        $now = ['quantity_milli' => 0, 'value_minor' => 0];
        foreach ($this->inventories->query(Balance::class, $company)->where('item_id', $item->getKey())->whereIn('warehouse_id', $warehouses)->get() as $balance) {
            $now['quantity_milli'] += (int) $balance->quantity_milli;
            $now['value_minor'] += (int) $balance->value_minor;
        }
        $moves = $this->inventories->query(Move::class, $company)->where('item_id', $item->getKey())->whereIn('warehouse_id', $warehouses);
        if ((clone $moves)->whereBetween('moved_on', [$from, $to])->count() > self::MAX_LEDGER) {
            return ['opening' => $now, 'rows' => [], 'closing' => $now, 'too_many' => true];
        }
        // Opening: today's balance less everything from the first day on.
        $opening = $now;
        foreach ((clone $moves)->where('moved_on', '>=', $from)->select(['quantity_milli', 'value_minor'])->cursor() as $move) {
            $opening['quantity_milli'] -= (int) $move->quantity_milli;
            $opening['value_minor'] -= (int) $move->value_minor;
        }
        $running = $opening;
        $rows = [];
        foreach ((clone $moves)->whereBetween('moved_on', [$from, $to])->orderBy('moved_on')->orderBy('id')->get() as $move) {
            $running['quantity_milli'] += (int) $move->quantity_milli;
            $running['value_minor'] += (int) $move->value_minor;
            $rows[] = ['id' => $move->getKey(), 'moved_on' => $move->moved_on->toDateString(), 'kind' => $move->kind, 'warehouse_id' => $move->warehouse_id,
                'quantity_milli' => (int) $move->quantity_milli, 'value_minor' => (int) $move->value_minor, 'source_module' => $move->source_module,
                'source_type' => $move->source_type, 'source_id' => $move->source_id, 'balance_milli' => $running['quantity_milli'], 'balance_minor' => $running['value_minor']];
        }

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $running, 'too_many' => false];
    }

    private static function unitCost(Balance $balance): int
    {
        return $balance->quantity_milli > 0 ? intdiv((int) $balance->value_minor * 1000 + intdiv((int) $balance->quantity_milli, 2), (int) $balance->quantity_milli) : (int) ($balance->last_unit_cost_minor ?? 0);
    }
}
