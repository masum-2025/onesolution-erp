<?php

namespace Modules\FactoryErp\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\FactoryErp\Models\BomLine;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;
use Modules\FactoryErp\Models\Style;
use Modules\Inventory\Services\Stock;

/**
 * What an order needs from the store: for each item of the style's bill of
 * materials, the pieces it applies to (every piece, or one size and/or
 * colour) times its consumption (per piece or per dozen) plus wastage,
 * rounded up to the item unit's thousandth; against what all the company's
 * warehouses hold now, and what is short. Integers only:
 *
 *     need = ceil(pieces × consumption × (10000 + wastage_bp) / (per × 10000))
 *
 * with per = 12 for a dozen, 1 for a piece.
 */
class Requirements
{
    public function __construct(private Floor $floor, private Styles $styles, private Stock $stock) {}

    /**
     * @return list<array{item_id: string, sku: string, name: string, unit_id: ?string, need_milli: int, on_hand_milli: int, short_milli: int}>
     */
    public function of(Organization $company, Order $order): array
    {
        $style = $this->floor->query(Style::class, $company)->whereKey($order->style_id)->firstOrFail();
        $lines = $this->floor->query(OrderLine::class, $company)->where('order_id', $order->getKey())->get();
        $per = $style->consumption_basis === 'dozen' ? 12 : 1;

        $needs = [];
        foreach ($this->styles->bom($company, $style) as $bom) {
            $pieces = (int) $lines->filter(fn (OrderLine $line) => self::applies($bom, $line))->sum('quantity');
            $needs[$bom->item_id] = ($needs[$bom->item_id] ?? 0) + self::need($pieces, $bom->consumption_milli, $bom->wastage_bp, $per);
        }
        $items = collect($this->stock->items($company, array_keys($needs)))->keyBy('id');
        $onHand = $this->stock->onHandAll($company, array_keys($needs));

        return collect($needs)->map(fn (int $need, string $itemId) => [
            'item_id' => $itemId, 'sku' => $items[$itemId]['sku'] ?? '—', 'name' => $items[$itemId]['name'] ?? '—', 'unit_id' => $items[$itemId]['unit_id'] ?? null,
            'need_milli' => $need, 'on_hand_milli' => max(0, $onHand[$itemId] ?? 0), 'short_milli' => max(0, $need - max(0, $onHand[$itemId] ?? 0)),
        ])->sortByDesc('short_milli')->values()->all();
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
