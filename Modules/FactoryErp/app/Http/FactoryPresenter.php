<?php

namespace Modules\FactoryErp\Http;

use Illuminate\Support\Collection;
use Modules\FactoryErp\Models\BomLine;
use Modules\FactoryErp\Models\Field;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;
use Modules\FactoryErp\Models\Style;

/** Styles, orders and own fields as the screens read them. */
class FactoryPresenter
{
    /**
     * @param  Collection<int, BomLine>|null  $bom
     * @return array<string, mixed>
     */
    public function style(Style $style, ?Collection $bom = null): array
    {
        return [
            'id' => $style->getKey(),
            ...$style->only(['buyer_id', 'code', 'name', 'product_type', 'sizes', 'colors', 'consumption_basis', 'note', 'is_active', 'version']),
            'extra' => (object) ($style->extra ?? []),
            ...($bom === null ? [] : ['bom' => $bom->map(fn (BomLine $line) => $line->only(['id', 'item_id', 'consumption_milli', 'wastage_bp', 'size', 'color', 'note']))->values()]),
        ];
    }

    /**
     * @param  Collection<int, OrderLine>|null  $lines
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function order(Order $order, ?Collection $lines = null, array $extra = []): array
    {
        return [
            'id' => $order->getKey(),
            ...$order->only(['unit_id', 'number', 'buyer_id', 'style_id', 'po_number', 'currency', 'unit_price_minor', 'status', 'note', 'cancelled_reason', 'version']),
            'ship_on' => $order->ship_on->toDateString(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'extra' => (object) ($order->extra ?? []),
            ...($lines === null ? [] : [
                'lines' => $lines->map(fn (OrderLine $line) => $line->only(['size', 'color', 'quantity']))->values(),
                'pieces' => (int) $lines->sum('quantity'),
                // Never above PHP's integers: pieces and price are bounded by validation.
                'value_minor' => (int) $lines->sum('quantity') * (int) $order->unit_price_minor,
            ]),
            ...$extra,
        ];
    }

    /** @return array<string, mixed> */
    public function field(Field $field): array
    {
        return [
            'id' => $field->getKey(), 'entity' => $field->entity, 'key' => $field->key, 'label' => $field->textIn('label'), 'labels' => $field->texts('label'),
            'type' => $field->type, 'options' => array_map(fn (array $option) => ['value' => $option['value'], 'label' => $option['label'][app()->getLocale()] ?? $option['label']['en'] ?? $option['value'], 'labels' => $option['label'] ?? []], $field->options ?? []),
            'is_required' => $field->is_required, 'on_print' => $field->on_print, 'sort_order' => $field->sort_order, 'is_active' => $field->is_active, 'version' => $field->version,
        ];
    }
}
