<?php

namespace Modules\Payroll\Http;

use Illuminate\Support\Collection;
use Modules\Payroll\Models\Adjustment;
use Modules\Payroll\Models\Component;
use Modules\Payroll\Models\PaymentDetail;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\Salary;
use Modules\Payroll\Models\Slip;
use Modules\Payroll\Models\SlipLine;
use Modules\Payroll\Models\Structure;
use Modules\Payroll\Models\StructureItem;

/**
 * API shapes of payroll. Amounts are integer minor units of the run's
 * currency; account numbers are always masked.
 */
class PayrollPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function component(Component $component): array
    {
        return [
            'id' => $component->getKey(), 'code' => $component->code, 'name' => $component->name, 'names' => $component->texts('name'),
            'kind' => $component->kind, 'taxable' => $component->taxable, 'prorated' => $component->prorated, 'sort' => $component->sort,
            'is_active' => $component->is_active, 'version' => $component->version,
        ];
    }

    /**
     * @param  Collection<int, StructureItem>  $items
     * @return array<string, mixed>
     */
    public function structure(Structure $structure, Collection $items): array
    {
        return [
            'id' => $structure->getKey(), 'code' => $structure->code, 'name' => $structure->name, 'names' => $structure->texts('name'),
            'is_active' => $structure->is_active, 'version' => $structure->version,
            'items' => $items->map(fn (StructureItem $item) => $item->only(['component_id', 'calc', 'amount_minor', 'rate_bp']))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salary(Salary $salary): array
    {
        return [
            'id' => $salary->getKey(), 'employee_id' => $salary->employee_id, 'structure_id' => $salary->structure_id, 'basic_minor' => $salary->basic_minor,
            'effective_from' => $salary->effective_from->toDateString(), 'effective_to' => $salary->effective_to?->toDateString(), 'reason' => $salary->reason,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function paymentDetail(?PaymentDetail $detail): ?array
    {
        return $detail === null ? null : [
            'method' => $detail->method, 'provider' => $detail->provider, 'account_name' => $detail->account_name,
            'account_number' => $detail->maskedNumber(), 'branch' => $detail->branch, 'version' => $detail->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(Run $run, array $can = [], int $approvals = 0, int $needed = 1): array
    {
        return [
            'id' => $run->getKey(), 'period' => $run->period, 'period_from' => $run->period_from->toDateString(), 'period_to' => $run->period_to->toDateString(),
            'status' => $run->status, 'currency' => $run->currency_code, 'employees' => $run->employees,
            'earnings_minor' => $run->earnings_minor, 'deductions_minor' => $run->deductions_minor, 'tax_minor' => $run->tax_minor, 'net_minor' => $run->net_minor,
            'calculated_at' => $run->calculated_at?->toIso8601String(), 'submitted_at' => $run->submitted_at?->toIso8601String(),
            'reject_reason' => $run->reject_reason, 'approved_at' => $run->approved_at?->toIso8601String(), 'paid_on' => $run->paid_on?->toDateString(),
            'journal_id' => $run->journal_id, 'payment_journal_id' => $run->payment_journal_id,
            'approvals' => $approvals, 'approvals_needed' => $needed, 'version' => $run->version,
            ...($can === [] ? [] : ['can' => $can]),
        ];
    }

    /**
     * @param  Collection<int, SlipLine>|null  $lines
     * @return array<string, mixed>
     */
    public function slip(Slip $slip, ?Collection $lines = null, ?Run $run = null): array
    {
        return [
            'id' => $slip->getKey(), 'run_id' => $slip->run_id, 'employee_id' => $slip->employee_id, 'employee_code' => $slip->employee_code,
            'employee_name' => $slip->employee_name, 'unit_id' => $slip->unit_id, 'basic_minor' => $slip->basic_minor,
            'period_days' => $slip->period_days, 'employed_days' => $slip->employed_days, 'absent_days' => $slip->absent_days, 'half_days' => $slip->half_days,
            'overtime_minutes' => $slip->overtime_minutes, 'late_minutes' => $slip->late_minutes,
            'earnings_minor' => $slip->earnings_minor, 'deductions_minor' => $slip->deductions_minor, 'tax_minor' => $slip->tax_minor, 'net_minor' => $slip->net_minor,
            'problem' => $slip->problem,
            ...($run === null ? [] : ['period' => $run->period, 'currency' => $run->currency_code, 'paid_on' => $run->paid_on?->toDateString(), 'company' => $run->organization?->name]),
            ...($lines === null ? [] : ['lines' => $lines->map(fn (SlipLine $line) => [
                'kind' => $line->kind, 'code' => $line->code, 'name' => $line->name, 'amount_minor' => $line->amount_minor, 'taxable' => $line->taxable,
            ])->values()->all()]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adjustment(Adjustment $adjustment): array
    {
        return $adjustment->only(['id', 'employee_id', 'kind', 'label', 'amount_minor', 'taxable']);
    }
}
