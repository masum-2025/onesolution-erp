<?php

namespace Modules\Pos\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Pos\Models\Payment;
use Modules\Pos\Models\Register;
use Modules\Pos\Models\Sale;
use Modules\Pos\Models\SaleLine;
use Modules\Pos\Models\Session;

/** Point of sale in the client's data export: counters, shifts, sales and returns with lines and payments. */
class PosExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'pos';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $texts = fn (Model $model, string $field) => json_encode($model->texts($field), JSON_UNESCAPED_UNICODE);

        return [
            'registers' => $this->rows(Register::class, $organization, $organizationIds, fn (Register $row) => [
                'id' => $row->getKey(), 'code' => $row->code, 'name' => $texts($row, 'name'), 'unit_id' => $row->unit_id, 'warehouse_id' => $row->warehouse_id,
                'payment_methods' => implode(',', $row->payment_methods), 'is_active' => $row->is_active,
            ]),
            'sessions' => $this->rows(Session::class, $organization, $organizationIds, fn (Session $row) => [
                'id' => $row->getKey(), 'register_id' => $row->register_id, 'status' => $row->status, 'opened_at' => $row->opened_at->toIso8601String(),
                'closed_at' => $row->closed_at?->toIso8601String(), 'opening_float_minor' => $row->opening_float_minor, 'expected_cash_minor' => $row->expected_cash_minor,
                'counted_cash_minor' => $row->counted_cash_minor, 'variance_minor' => $row->variance_minor, 'currency_code' => $row->currency_code,
            ]),
            'sales' => $this->rows(Sale::class, $organization, $organizationIds, fn (Sale $row) => [
                'id' => $row->getKey(), 'number' => $row->number, 'kind' => $row->kind, 'register_id' => $row->register_id, 'session_id' => $row->session_id,
                'original_sale_id' => $row->original_sale_id, 'sold_at' => $row->sold_at->toIso8601String(), 'customer_name' => $row->customer_name,
                'subtotal_minor' => $row->subtotal_minor, 'discount_minor' => $row->discount_minor, 'tax_minor' => $row->tax_minor, 'total_minor' => $row->total_minor,
                'cost_minor' => $row->cost_minor, 'currency_code' => $row->currency_code, 'offline' => $row->offline, 'review_reason' => $row->review_reason, 'journal_id' => $row->journal_id,
            ]),
            'sale_lines' => $this->rows(SaleLine::class, $organization, $organizationIds, fn (SaleLine $row) => [
                'sale_id' => $row->sale_id, 'line_no' => $row->line_no, 'item_id' => $row->item_id, 'sku' => $row->sku, 'name' => $texts($row, 'name'),
                'quantity_milli' => $row->quantity_milli, 'unit_price_minor' => $row->unit_price_minor, 'discount_minor' => $row->discount_minor,
                'tax_minor' => $row->tax_minor, 'total_minor' => $row->total_minor, 'cost_minor' => $row->cost_minor,
            ]),
            'payments' => $this->rows(Payment::class, $organization, $organizationIds, fn (Payment $row) => ['sale_id' => $row->sale_id, 'method' => $row->method, 'amount_minor' => $row->amount_minor, 'reference' => $row->reference]),
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
