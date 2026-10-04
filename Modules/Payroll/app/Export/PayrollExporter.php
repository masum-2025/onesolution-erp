<?php

namespace Modules\Payroll\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Payroll\Models\Adjustment;
use Modules\Payroll\Models\Component;
use Modules\Payroll\Models\PaymentDetail;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunApproval;
use Modules\Payroll\Models\Salary;
use Modules\Payroll\Models\Slip;
use Modules\Payroll\Models\SlipLine;
use Modules\Payroll\Models\Structure;
use Modules\Payroll\Models\StructureItem;

/**
 * Payroll in the client's data export (the client owns its data, so account
 * numbers are included in full, as HRM includes national ids). Runs
 * without a tenant context, reading the client's own database.
 */
class PayrollExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'payroll';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $texts = fn (Model $model, string $field) => json_encode($model->texts($field), JSON_UNESCAPED_UNICODE);

        return [
            'components' => $this->rows(Component::class, $organization, $organizationIds, fn (Component $row) => [
                'id' => $row->getKey(), 'code' => $row->code, 'name' => $texts($row, 'name'), 'kind' => $row->kind,
                'taxable' => $row->taxable, 'prorated' => $row->prorated, 'is_active' => $row->is_active,
            ]),
            'structures' => $this->rows(Structure::class, $organization, $organizationIds, fn (Structure $row) => [
                'id' => $row->getKey(), 'code' => $row->code, 'name' => $texts($row, 'name'), 'is_active' => $row->is_active,
            ]),
            'structure_items' => $this->rows(StructureItem::class, $organization, $organizationIds, fn (StructureItem $row) => [
                'id' => $row->getKey(), 'structure_id' => $row->structure_id, 'component_id' => $row->component_id,
                'calc' => $row->calc, 'amount_minor' => $row->amount_minor, 'rate_bp' => $row->rate_bp,
            ]),
            'salaries' => $this->rows(Salary::class, $organization, $organizationIds, fn (Salary $row) => [
                'id' => $row->getKey(), 'employee_id' => $row->employee_id, 'structure_id' => $row->structure_id, 'basic_minor' => $row->basic_minor,
                'effective_from' => $row->effective_from->toDateString(), 'effective_to' => $row->effective_to?->toDateString(), 'reason' => $row->reason,
            ]),
            'payment_details' => $this->rows(PaymentDetail::class, $organization, $organizationIds, fn (PaymentDetail $row) => [
                'employee_id' => $row->employee_id, 'method' => $row->method, 'provider' => $row->provider,
                'account_name' => $row->account_name, 'account_number' => $row->account_number, 'branch' => $row->branch,
            ]),
            'runs' => $this->rows(Run::class, $organization, $organizationIds, fn (Run $row) => [
                'id' => $row->getKey(), 'period' => $row->period, 'status' => $row->status, 'currency_code' => $row->currency_code,
                'employees' => $row->employees, 'earnings_minor' => $row->earnings_minor, 'deductions_minor' => $row->deductions_minor,
                'tax_minor' => $row->tax_minor, 'net_minor' => $row->net_minor, 'paid_on' => $row->paid_on?->toDateString(), 'journal_id' => $row->journal_id,
            ]),
            'run_approvals' => $this->rows(RunApproval::class, $organization, $organizationIds, fn (RunApproval $row) => [
                'run_id' => $row->run_id, 'level' => $row->level, 'user_id' => $row->user_id, 'approved_at' => $row->created_at?->toIso8601String(),
            ]),
            'slips' => $this->rows(Slip::class, $organization, $organizationIds, fn (Slip $row) => [
                'id' => $row->getKey(), 'run_id' => $row->run_id, 'unit_id' => $row->unit_id, 'employee_id' => $row->employee_id, 'employee_code' => $row->employee_code,
                'employee_name' => $row->employee_name, 'basic_minor' => $row->basic_minor, 'employed_days' => $row->employed_days, 'absent_days' => $row->absent_days,
                'half_days' => $row->half_days, 'overtime_minutes' => $row->overtime_minutes, 'late_minutes' => $row->late_minutes,
                'earnings_minor' => $row->earnings_minor, 'deductions_minor' => $row->deductions_minor, 'tax_minor' => $row->tax_minor, 'net_minor' => $row->net_minor,
            ]),
            'slip_lines' => $this->rows(SlipLine::class, $organization, $organizationIds, fn (SlipLine $row) => [
                'slip_id' => $row->slip_id, 'line_no' => $row->line_no, 'kind' => $row->kind, 'code' => $row->code,
                'name' => $texts($row, 'name'), 'amount_minor' => $row->amount_minor, 'taxable' => $row->taxable,
            ]),
            'adjustments' => $this->rows(Adjustment::class, $organization, $organizationIds, fn (Adjustment $row) => [
                'id' => $row->getKey(), 'run_id' => $row->run_id, 'employee_id' => $row->employee_id, 'kind' => $row->kind,
                'label' => $row->label, 'amount_minor' => $row->amount_minor, 'taxable' => $row->taxable,
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
