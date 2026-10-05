<?php

namespace Modules\Inventory\Http;

use Illuminate\Support\Collection;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Document;
use Modules\Inventory\Models\DocumentLine;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Move;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\StockCountLine;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;

/**
 * API shapes of inventory. Quantities are integer thousandths
 * (quantity_milli); money integer minor units of the company's currency.
 */
class InventoryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function unit(Unit $unit): array
    {
        return ['id' => $unit->getKey(), 'code' => $unit->code, 'name' => $unit->name, 'names' => $unit->texts('name'), 'decimals' => $unit->decimals, 'is_active' => $unit->is_active, 'version' => $unit->version];
    }

    /**
     * @return array<string, mixed>
     */
    public function category(Category $category): array
    {
        return ['id' => $category->getKey(), 'code' => $category->code, 'name' => $category->name, 'names' => $category->texts('name'), 'parent_id' => $category->parent_id, 'is_active' => $category->is_active, 'version' => $category->version];
    }

    /**
     * @return array<string, mixed>
     */
    public function warehouse(Warehouse $warehouse): array
    {
        return ['id' => $warehouse->getKey(), 'code' => $warehouse->code, 'name' => $warehouse->name, 'names' => $warehouse->texts('name'), 'unit_id' => $warehouse->unit_id, 'is_active' => $warehouse->is_active, 'version' => $warehouse->version];
    }

    /**
     * @param  array{quantity_milli: int, value_minor: int}|null  $stock
     * @return array<string, mixed>
     */
    public function item(Item $item, ?array $stock = null): array
    {
        return [
            'id' => $item->getKey(), 'sku' => $item->sku, 'barcode' => $item->barcode, 'name' => $item->name, 'names' => $item->texts('name'),
            'category_id' => $item->category_id, 'unit_id' => $item->unit_id, 'kind' => $item->kind, 'track_batches' => $item->track_batches,
            'sale_price_minor' => $item->sale_price_minor, 'tax_code_id' => $item->tax_code_id, 'reorder_level_milli' => $item->reorder_level_milli,
            'reorder_quantity_milli' => $item->reorder_quantity_milli, 'description' => $item->description, 'is_active' => $item->is_active, 'version' => $item->version,
            ...($stock === null ? [] : ['stock' => $stock]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function balance(Balance $balance): array
    {
        return [
            'item_id' => $balance->item_id, 'warehouse_id' => $balance->warehouse_id, 'quantity_milli' => $balance->quantity_milli, 'value_minor' => $balance->value_minor,
            'unit_cost_minor' => $balance->quantity_milli > 0 ? intdiv($balance->value_minor * 1000 + intdiv($balance->quantity_milli, 2), $balance->quantity_milli) : $balance->last_unit_cost_minor,
            'method' => $balance->method,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function move(Move $move): array
    {
        return [
            'id' => $move->getKey(), 'item_id' => $move->item_id, 'warehouse_id' => $move->warehouse_id, 'batch_id' => $move->batch_id, 'kind' => $move->kind,
            'quantity_milli' => $move->quantity_milli, 'value_minor' => $move->value_minor, 'moved_on' => $move->moved_on->toDateString(),
            'source_module' => $move->source_module, 'source_type' => $move->source_type, 'source_id' => $move->source_id, 'note' => $move->note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function batch(Batch $batch, int $quantityMilli = 0): array
    {
        return ['id' => $batch->getKey(), 'item_id' => $batch->item_id, 'number' => $batch->number, 'expires_on' => $batch->expires_on?->toDateString(), 'quantity_milli' => $quantityMilli];
    }

    /**
     * @param  Collection<int, DocumentLine>|null  $lines
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public function document(Document $document, ?Collection $lines = null, array $can = []): array
    {
        return [
            'id' => $document->getKey(), 'type' => $document->type, 'number' => $document->number, 'status' => $document->status,
            'warehouse_id' => $document->warehouse_id, 'to_warehouse_id' => $document->to_warehouse_id, 'document_date' => $document->document_date->toDateString(),
            'counterparty' => $document->counterparty, 'reference' => $document->reference, 'reason' => $document->reason, 'value_minor' => $document->value_minor,
            'currency' => $document->currency_code, 'reject_reason' => $document->reject_reason, 'posted_at' => $document->posted_at?->toIso8601String(),
            'received_at' => $document->received_at?->toIso8601String(), 'journal_id' => $document->journal_id, 'version' => $document->version,
            ...($lines === null ? [] : ['lines' => $lines->map(fn (DocumentLine $line) => $line->only([
                'id', 'line_no', 'item_id', 'quantity_milli', 'received_milli', 'unit_cost_minor', 'batch_number', 'value_minor', 'note',
            ]) + ['expires_on' => $line->expires_on?->toDateString()])->values()->all()]),
            ...($can === [] ? [] : ['can' => $can]),
        ];
    }

    /**
     * @param  Collection<int, StockCountLine>|null  $lines
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public function count(StockCount $count, ?Collection $lines = null, array $can = []): array
    {
        return [
            'id' => $count->getKey(), 'number' => $count->number, 'warehouse_id' => $count->warehouse_id, 'category_id' => $count->category_id,
            'status' => $count->status, 'counted_on' => $count->counted_on->toDateString(), 'variance_value_minor' => $count->variance_value_minor,
            'currency' => $count->currency_code, 'reject_reason' => $count->reject_reason, 'posted_at' => $count->posted_at?->toIso8601String(),
            'journal_id' => $count->journal_id, 'version' => $count->version,
            ...($lines === null ? [] : ['lines' => $lines->map(fn (StockCountLine $line) => $line->only(['item_id', 'expected_milli', 'counted_milli', 'value_minor']))->values()->all()]),
            ...($can === [] ? [] : ['can' => $can]),
        ];
    }
}
