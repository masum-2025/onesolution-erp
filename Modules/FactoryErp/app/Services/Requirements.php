<?php

namespace Modules\FactoryErp\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\FactoryErp\Models\BomLine;
use Modules\FactoryErp\Models\IssueLine;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;
use Modules\FactoryErp\Models\Style;
use Modules\Inventory\Services\Stock;

/**
 * What an order needs from the store: for each item of the style's bill of
 * materials, the pieces it applies to (every piece, or one size and/or
 * colour) times its consumption (per piece or per dozen) plus wastage,
 * rounded up to the item unit's thousandth; what has gone to the floor for
 * it (issued less returned), what is still to go, and what all the
 * company's warehouses hold now. Integers only:
 *
 *     need = ceil(pieces × consumption × (10000 + wastage_bp) / (per × 10000))
 *
 * with per = 12 for a dozen, 1 for a piece.
 */
class Requirements
{
    public function __construct(private Floor $floor, private Styles $styles, private Stock $stock) {}

    /**
     * @return list<array{item_id: string, sku: string, name: string, unit_id: ?string, need_milli: int, issued_milli: int, issued_cost_minor: int, to_issue_milli: int, on_hand_milli: int, short_milli: int}>
     */
    public function of(Organization $company, Order $order): array
    {
        $needs = $this->needs($company, $order);
        $issued = $this->issued($company, $order);
        $ids = array_keys($needs + $issued);
        $items = collect($this->stock->items($company, $ids))->keyBy('id');
        $onHand = $this->stock->onHandAll($company, $ids);

        return collect($ids)->map(function (string $itemId) use ($needs, $issued, $items, $onHand) {
            $need = $needs[$itemId] ?? 0;
            $toIssue = max(0, $need - ($issued[$itemId]['quantity'] ?? 0));
            $held = max(0, $onHand[$itemId] ?? 0);

            return [
                'item_id' => $itemId, 'sku' => $items[$itemId]['sku'] ?? '—', 'name' => $items[$itemId]['name'] ?? '—', 'unit_id' => $items[$itemId]['unit_id'] ?? null,
                'need_milli' => $need, 'issued_milli' => $issued[$itemId]['quantity'] ?? 0, 'issued_cost_minor' => $issued[$itemId]['cost'] ?? 0,
                'to_issue_milli' => $toIssue, 'on_hand_milli' => $held, 'short_milli' => max(0, $toIssue - $held),
            ];
        })->sortByDesc('short_milli')->values()->all();
    }

    /**
     * Thousandths of each item the whole order needs.
     *
     * @return array<string, int>
     */
    public function needs(Organization $company, Order $order): array
    {
        $style = $this->floor->query(Style::class, $company)->whereKey($order->style_id)->firstOrFail();
        $lines = $this->floor->query(OrderLine::class, $company)->where('order_id', $order->getKey())->get();
        $per = $style->consumption_basis === 'dozen' ? 12 : 1;

        $needs = [];
        foreach ($this->styles->bom($company, $style) as $bom) {
            $pieces = (int) $lines->filter(fn (OrderLine $line) => self::applies($bom, $line))->sum('quantity');
            $needs[$bom->item_id] = ($needs[$bom->item_id] ?? 0) + self::need($pieces, $bom->consumption_milli, $bom->wastage_bp, $per);
        }

        return $needs;
    }

    /**
     * What is on the floor for the order per item: issued less returned, quantity and cost.
     *
     * @return array<string, array{quantity: int, cost: int}>
     */
    public function issued(Organization $company, Order $order): array
    {
        $rows = $this->floor->query(IssueLine::class, $company)->where('fac_issue_lines.order_id', $order->getKey())
            ->join('fac_issues', 'fac_issues.id', '=', 'fac_issue_lines.issue_id')
            ->get(['fac_issue_lines.item_id', 'fac_issue_lines.quantity_milli', 'fac_issue_lines.cost_minor', 'fac_issues.kind']);
        $issued = [];
        foreach ($rows as $row) {
            $sign = $row->kind === 'return' ? -1 : 1;
            $issued[$row->item_id]['quantity'] = ($issued[$row->item_id]['quantity'] ?? 0) + $sign * (int) $row->quantity_milli;
            $issued[$row->item_id]['cost'] = ($issued[$row->item_id]['cost'] ?? 0) + $sign * (int) $row->cost_minor;
        }

        return $issued;
    }

    /** Thousandths of the item's unit for these pieces, rounded up. */
    public static function need(int $pieces, int $consumptionMilli, int $wastageBp, int $per): int
    {
        $top = $pieces * $consumptionMilli * (10000 + $wastageBp);
        $bottom = $per * 10000;

        return intdiv($top + $bottom - 1, $bottom);
    }

    private static function applies(BomLine $bom, OrderLine $line): bool
    {
        return ($bom->size === null || $bom->size === $line->size) && ($bom->color === null || $bom->color === $line->color);
    }
}
