<?php

namespace Modules\FactoryErp\Http;

use Illuminate\Support\Collection;
use Modules\FactoryErp\Models\BomLine;
use Modules\FactoryErp\Models\Cutting;
use Modules\FactoryErp\Models\CuttingLine;
use Modules\FactoryErp\Models\DefectType;
use Modules\FactoryErp\Models\Field;
use Modules\FactoryErp\Models\Inspection;
use Modules\FactoryErp\Models\InspectionDefect;
use Modules\FactoryErp\Models\Issue;
use Modules\FactoryErp\Models\IssueLine;
use Modules\FactoryErp\Models\Line;
use Modules\FactoryErp\Models\LineDay;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;
use Modules\FactoryErp\Models\Output;
use Modules\FactoryErp\Models\OutputLine;
use Modules\FactoryErp\Models\Receipt;
use Modules\FactoryErp\Models\ReceiptLine;
use Modules\FactoryErp\Models\Style;
use Modules\FactoryErp\Services\Quality;

/** Styles, orders, materials issued, lays cut and own fields as the screens read them. */
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
            ...$style->only(['buyer_id', 'code', 'name', 'product_type', 'sizes', 'colors', 'consumption_basis', 'finished_item_id', 'note', 'is_active', 'version']),
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

    /**
     * @param  Collection<int, IssueLine>  $lines
     * @return array<string, mixed>
     */
    public function issue(Issue $issue, Collection $lines): array
    {
        return [
            'id' => $issue->getKey(),
            ...$issue->only(['order_id', 'warehouse_id', 'kind', 'cost_minor', 'currency', 'journal_id', 'note', 'created_by']),
            'issued_on' => $issue->issued_on->toDateString(),
            'created_at' => $issue->created_at?->toIso8601String(),
            'lines' => $lines->map(fn (IssueLine $line) => $line->only(['item_id', 'quantity_milli', 'cost_minor']))->values(),
        ];
    }

    /**
     * @param  Collection<int, CuttingLine>  $lines
     * @return array<string, mixed>
     */
    public function cutting(Cutting $cutting, Collection $lines): array
    {
        return [
            'id' => $cutting->getKey(),
            ...$cutting->only(['order_id', 'lay_number', 'pieces', 'note', 'created_by', 'void_reason', 'version']),
            'cut_on' => $cutting->cut_on->toDateString(),
            'voided_at' => $cutting->voided_at?->toIso8601String(),
            'lines' => $lines->map(fn (CuttingLine $line) => $line->only(['size', 'color', 'quantity']))->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function defectType(DefectType $type): array
    {
        return ['id' => $type->getKey(), 'code' => $type->code, 'name' => $type->textIn('name'), 'names' => $type->texts('name'), ...$type->only(['sort_order', 'is_active', 'version'])];
    }

    /**
     * @param  Collection<int, InspectionDefect>  $defects
     * @return array<string, mixed>
     */
    public function inspection(Inspection $inspection, Collection $defects): array
    {
        return [
            'id' => $inspection->getKey(),
            ...$inspection->only(['unit_id', 'order_id', 'line_id', 'kind', 'hour', 'checked', 'defects', 'lot_size', 'aql', 'accept', 'result', 'note', 'created_by', 'void_reason', 'version']),
            'inspected_on' => $inspection->inspected_on->toDateString(),
            'dhu_bp' => Quality::dhuBp($inspection->defects, $inspection->checked),
            'created_at' => $inspection->created_at?->toIso8601String(),
            'voided_at' => $inspection->voided_at?->toIso8601String(),
            'by_type' => $defects->map(fn (InspectionDefect $defect) => $defect->only(['defect_type_id', 'count']))->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function lineDay(LineDay $day): array
    {
        return ['id' => $day->getKey(), ...$day->only(['line_id', 'operators', 'helpers', 'minutes', 'note', 'version']), 'worked_on' => $day->worked_on->toDateString()];
    }

    /** @return array<string, mixed> */
    public function line(Line $line): array
    {
        return ['id' => $line->getKey(), ...$line->only(['unit_id', 'code', 'name', 'machines', 'is_active', 'version'])];
    }

    /**
     * @param  Collection<int, OutputLine>  $lines
     * @return array<string, mixed>
     */
    public function output(Output $output, Collection $lines): array
    {
        return [
            'id' => $output->getKey(),
            ...$output->only(['unit_id', 'line_id', 'order_id', 'hour', 'good', 'rejects', 'created_by', 'void_reason', 'version']),
            'worked_on' => $output->worked_on->toDateString(),
            'created_at' => $output->created_at?->toIso8601String(),
            'voided_at' => $output->voided_at?->toIso8601String(),
            'lines' => $lines->map(fn (OutputLine $line) => $line->only(['size', 'color', 'good', 'rejects']))->values(),
        ];
    }

    /**
     * @param  Collection<int, ReceiptLine>  $lines
     * @return array<string, mixed>
     */
    public function receipt(Receipt $receipt, Collection $lines): array
    {
        return [
            'id' => $receipt->getKey(),
            ...$receipt->only(['order_id', 'warehouse_id', 'item_id', 'pieces', 'is_last', 'cost_minor', 'currency', 'journal_id', 'note', 'created_by']),
            'received_on' => $receipt->received_on->toDateString(),
            'lines' => $lines->map(fn (ReceiptLine $line) => $line->only(['size', 'color', 'quantity']))->values(),
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
