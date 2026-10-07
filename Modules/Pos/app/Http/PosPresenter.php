<?php

namespace Modules\Pos\Http;

use Illuminate\Support\Collection;
use Modules\Pos\Models\Payment;
use Modules\Pos\Models\Register;
use Modules\Pos\Models\Sale;
use Modules\Pos\Models\SaleLine;
use Modules\Pos\Models\Session;

/** API shapes of point of sale (money in minor units, quantities in thousandths). */
class PosPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function register(Register $register): array
    {
        return [
            'id' => $register->getKey(), 'code' => $register->code, 'name' => $register->name, 'names' => $register->texts('name'), 'unit_id' => $register->unit_id,
            'warehouse_id' => $register->warehouse_id, 'payment_methods' => $register->payment_methods, 'is_active' => $register->is_active, 'version' => $register->version,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $report
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public function session(Session $session, ?array $report = null, array $can = []): array
    {
        return [
            'id' => $session->getKey(), 'register_id' => $session->register_id, 'status' => $session->status, 'opened_by' => $session->opened_by,
            'opened_at' => $session->opened_at->toIso8601String(), 'opening_float_minor' => $session->opening_float_minor, 'closed_at' => $session->closed_at?->toIso8601String(),
            'expected_cash_minor' => $session->expected_cash_minor, 'counted_cash_minor' => $session->counted_cash_minor, 'variance_minor' => $session->variance_minor,
            'close_note' => $session->close_note, 'review_note' => $session->review_note, 'sales_count' => $session->sales_count, 'sales_minor' => $session->sales_minor,
            'returns_minor' => $session->returns_minor, 'tax_minor' => $session->tax_minor, 'currency' => $session->currency_code, 'version' => $session->version,
            ...($report === null ? [] : ['report' => $report]),
            ...($can === [] ? [] : ['can' => $can]),
        ];
    }

    /**
     * @param  Collection<int, SaleLine>|null  $lines
     * @param  Collection<int, Payment>|null  $payments
     * @return array<string, mixed>
     */
    public function sale(Sale $sale, ?Collection $lines = null, ?Collection $payments = null, array $extra = []): array
    {
        return [
            'id' => $sale->getKey(), 'number' => $sale->number, 'kind' => $sale->kind, 'register_id' => $sale->register_id, 'session_id' => $sale->session_id,
            'original_sale_id' => $sale->original_sale_id, 'sold_at' => $sale->sold_at->toIso8601String(), 'customer_name' => $sale->customer_name, 'customer_phone' => $sale->customer_phone, 'customer_id' => $sale->customer_id, 'reason' => $sale->reason,
            'subtotal_minor' => $sale->subtotal_minor, 'discount_minor' => $sale->discount_minor, 'tax_minor' => $sale->tax_minor, 'total_minor' => $sale->total_minor,
            'paid_minor' => $sale->paid_minor, 'change_minor' => $sale->change_minor, 'currency' => $sale->currency_code, 'prices_include_tax' => $sale->prices_include_tax,
            'offline' => $sale->offline, 'review_reason' => $sale->review_reason, 'journal_id' => $sale->journal_id, 'version' => $sale->version,
            ...($lines === null ? [] : ['lines' => $lines->map(fn (SaleLine $line) => [
                'id' => $line->getKey(), 'line_no' => $line->line_no, 'item_id' => $line->item_id, 'sku' => $line->sku, 'name' => $line->name,
                'quantity_milli' => $line->quantity_milli, 'unit_price_minor' => $line->unit_price_minor, 'discount_minor' => $line->discount_minor,
                'tax_rate_bp' => $line->tax_rate_bp, 'tax_minor' => $line->tax_minor, 'total_minor' => $line->total_minor, 'returned_milli' => $line->returned_milli,
            ])->values()->all()]),
            ...($payments === null ? [] : ['payments' => $payments->map(fn (Payment $payment) => $payment->only(['method', 'amount_minor', 'reference']))->values()->all()]),
            ...$extra,
        ];
    }
}
