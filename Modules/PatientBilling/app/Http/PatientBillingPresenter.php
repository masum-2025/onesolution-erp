<?php

namespace Modules\PatientBilling\Http;

use App\Models\User;
use Modules\PatientBilling\Models\Bill;
use Modules\PatientBilling\Models\BillLine;
use Modules\PatientBilling\Models\CashDay;
use Modules\PatientBilling\Models\DiscountRequest;
use Modules\PatientBilling\Models\Panel;
use Modules\PatientBilling\Models\Payment;
use Modules\PatientBilling\Models\Service;
use Modules\Patients\Directory\PatientRecord;

/** Patient billing records as the screens read them. */
class PatientBillingPresenter
{
    /** @return array<string, mixed> */
    public function service(Service $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'code' => $record->code,
            'name' => (object) $record->texts('name'),
            'category' => $record->category,
            'price_minor' => $record->price_minor,
            'tax_code_id' => $record->tax_code_id,
            'is_active' => $record->is_active,
            'currency' => $record->currency,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function panel(Panel $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'name' => $record->name,
            'kind' => $record->kind,
            'credit_limit_minor' => $record->credit_limit_minor,
            'discount_percent' => $record->discount_percent,
            'is_active' => $record->is_active,
            'currency' => $record->currency,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function patient(?PatientRecord $patient): ?array
    {
        return $patient === null ? null : [
            'id' => $patient->id, 'mrn' => $patient->mrn, 'name' => $patient->name, 'sex' => $patient->sex, 'age' => $patient->age, 'mobile' => $patient->mobile,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $patient
     * @return array<string, mixed>
     */
    public function bill(Bill $bill, ?array $patient, ?string $panelName = null): array
    {
        return [
            'id' => $bill->getKey(),
            'unit_id' => $bill->unit_id,
            'number' => $bill->number,
            'status' => $bill->status,
            'patient' => $patient,
            'panel_id' => $bill->panel_id,
            'panel_name' => $panelName,
            'subtotal_minor' => $bill->subtotal_minor,
            'panel_discount_minor' => $bill->panel_discount_minor,
            'discount_minor' => $bill->discount_minor,
            'discount_reason' => $bill->discount_reason,
            'tax_minor' => $bill->tax_minor,
            'total_minor' => $bill->total_minor,
            'paid_minor' => $bill->paid_minor,
            'refunded_minor' => $bill->refunded_minor,
            'kept_minor' => $bill->keptMinor(),
            'due_minor' => $bill->dueMinor(),
            'panel_due_minor' => $bill->panel_due_minor,
            'currency' => $bill->currency,
            'prices_include_tax' => $bill->prices_include_tax,
            'finalized_at' => $bill->finalized_at?->toIso8601String(),
            'void_reason' => $bill->void_reason,
            'version' => $bill->version,
            'created_at' => $bill->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function line(BillLine $line, ?string $locale): array
    {
        return [
            'id' => $line->getKey(),
            'service_id' => $line->service_id,
            'description' => $line->descriptionIn($locale),
            'source_module' => $line->source_module,
            'quantity_milli' => $line->quantity_milli,
            'unit_price_minor' => $line->unit_price_minor,
            'gross_minor' => $line->gross_minor,
            'discount_minor' => $line->discount_minor,
            'tax_rate_bp' => $line->tax_rate_bp,
            'tax_minor' => $line->tax_minor,
            'total_minor' => $line->total_minor,
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public function payment(Payment $payment, array $names = []): array
    {
        return [
            'id' => $payment->getKey(),
            'number' => $payment->number,
            'bill_id' => $payment->bill_id,
            'patient_id' => $payment->patient_id,
            'kind' => $payment->kind,
            'method' => $payment->method,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'reason' => $payment->reason,
            'received_by_name' => $names[$payment->received_by] ?? null,
            'received_at' => $payment->received_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public function discountRequest(DiscountRequest $request, array $names, ?Bill $bill = null, ?array $patient = null): array
    {
        return [
            'id' => $request->getKey(),
            'bill_id' => $request->bill_id,
            'bill_number' => $bill?->number,
            'bill_subtotal_minor' => $bill?->subtotal_minor,
            'currency' => $bill?->currency,
            'patient' => $patient,
            'amount_minor' => $request->amount_minor,
            'percent_bp' => $request->percent_bp,
            'reason' => $request->reason,
            'status' => $request->status,
            'mine' => $request->requested_by === auth()->id(),
            'requested_by_name' => $names[$request->requested_by] ?? null,
            'decided_by_name' => $request->decided_by === null ? null : ($names[$request->decided_by] ?? null),
            'note' => $request->note,
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public function cashDay(CashDay $day, array $names = [], ?int $expectedNow = null): array
    {
        return [
            'id' => $day->getKey(),
            'unit_id' => $day->unit_id,
            'user_id' => $day->user_id,
            'user_name' => $names[$day->user_id] ?? null,
            'mine' => $day->user_id === auth()->id(),
            'status' => $day->status,
            'currency' => $day->currency,
            'opening_float_minor' => $day->opening_float_minor,
            'expected_minor' => $day->expected_minor ?? $expectedNow,
            'counted_minor' => $day->counted_minor,
            'variance_minor' => $day->variance_minor,
            'opened_at' => $day->opened_at->toIso8601String(),
            'closed_at' => $day->closed_at?->toIso8601String(),
            'review_note' => $day->review_note,
            'version' => $day->version,
        ];
    }

    /**
     * @param  list<string|null>  $ids
     * @return array<string, string>
     */
    public function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : User::query()->whereKey($ids)->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }
}
