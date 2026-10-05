<?php

namespace Modules\Inventory\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
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
 * Inventory in the client's data export: the catalogue, every move, the
 * balances, documents and counts. Runs without a tenant context, reading
 * the client's own database.
 */
class InventoryExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'inventory';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $texts = fn (Model $model, string $field) => json_encode($model->texts($field), JSON_UNESCAPED_UNICODE);

        return [
            'units' => $this->rows(Unit::class, $organization, $organizationIds, fn (Unit $row) => ['id' => $row->getKey(), 'code' => $row->code, 'name' => $texts($row, 'name'), 'decimals' => $row->decimals, 'is_active' => $row->is_active]),
            'categories' => $this->rows(Category::class, $organization, $organizationIds, fn (Category $row) => ['id' => $row->getKey(), 'code' => $row->code, 'name' => $texts($row, 'name'), 'parent_id' => $row->parent_id, 'is_active' => $row->is_active]),
            'warehouses' => $this->rows(Warehouse::class, $organization, $organizationIds, fn (Warehouse $row) => ['id' => $row->getKey(), 'code' => $row->code, 'name' => $texts($row, 'name'), 'unit_id' => $row->unit_id, 'is_active' => $row->is_active]),
            'items' => $this->rows(Item::class, $organization, $organizationIds, fn (Item $row) => [
                'id' => $row->getKey(), 'sku' => $row->sku, 'barcode' => $row->barcode, 'name' => $texts($row, 'name'), 'category_id' => $row->category_id,
                'unit_id' => $row->unit_id, 'kind' => $row->kind, 'track_batches' => $row->track_batches, 'sale_price_minor' => $row->sale_price_minor,
                'reorder_level_milli' => $row->reorder_level_milli, 'is_active' => $row->is_active,
            ]),
            'batches' => $this->rows(Batch::class, $organization, $organizationIds, fn (Batch $row) => ['id' => $row->getKey(), 'item_id' => $row->item_id, 'number' => $row->number, 'expires_on' => $row->expires_on?->toDateString()]),
            'moves' => $this->rows(Move::class, $organization, $organizationIds, fn (Move $row) => [
                'id' => $row->getKey(), 'item_id' => $row->item_id, 'warehouse_id' => $row->warehouse_id, 'batch_id' => $row->batch_id, 'kind' => $row->kind,
                'quantity_milli' => $row->quantity_milli, 'value_minor' => $row->value_minor, 'moved_on' => $row->moved_on->toDateString(),
                'source_module' => $row->source_module, 'source_type' => $row->source_type, 'source_id' => $row->source_id,
            ]),
            'balances' => $this->rows(Balance::class, $organization, $organizationIds, fn (Balance $row) => [
                'item_id' => $row->item_id, 'warehouse_id' => $row->warehouse_id, 'quantity_milli' => $row->quantity_milli, 'value_minor' => $row->value_minor, 'method' => $row->method,
            ]),
            'documents' => $this->rows(Document::class, $organization, $organizationIds, fn (Document $row) => [
                'id' => $row->getKey(), 'type' => $row->type, 'number' => $row->number, 'status' => $row->status, 'warehouse_id' => $row->warehouse_id,
                'to_warehouse_id' => $row->to_warehouse_id, 'document_date' => $row->document_date->toDateString(), 'counterparty' => $row->counterparty,
                'reference' => $row->reference, 'reason' => $row->reason, 'value_minor' => $row->value_minor, 'currency_code' => $row->currency_code, 'journal_id' => $row->journal_id,
            ]),
            'document_lines' => $this->rows(DocumentLine::class, $organization, $organizationIds, fn (DocumentLine $row) => [
                'document_id' => $row->document_id, 'line_no' => $row->line_no, 'item_id' => $row->item_id, 'quantity_milli' => $row->quantity_milli,
                'received_milli' => $row->received_milli, 'unit_cost_minor' => $row->unit_cost_minor, 'batch_number' => $row->batch_number, 'value_minor' => $row->value_minor,
            ]),
            'counts' => $this->rows(StockCount::class, $organization, $organizationIds, fn (StockCount $row) => [
                'id' => $row->getKey(), 'number' => $row->number, 'warehouse_id' => $row->warehouse_id, 'status' => $row->status,
                'counted_on' => $row->counted_on->toDateString(), 'variance_value_minor' => $row->variance_value_minor, 'journal_id' => $row->journal_id,
            ]),
            'count_lines' => $this->rows(StockCountLine::class, $organization, $organizationIds, fn (StockCountLine $row) => [
                'count_id' => $row->count_id, 'item_id' => $row->item_id, 'expected_milli' => $row->expected_milli, 'counted_milli' => $row->counted_milli, 'value_minor' => $row->value_minor,
            ]),
        ];
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  list<string>  $organizationIds
     * @param  callable(T): array<string, scalar|null>  $row
     * @return iterable<array<string, scalar|null>>
     */
    private function rows(string $model, Organization $organization, array $organizationIds, callable $row): iterable
    {
        foreach (array_chunk($organizationIds, 500) as $chunk) {
            foreach ($model::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $chunk)->orderBy('id')->lazy(500) as $record) {
                yield $row($record);
            }
        }
    }
}
