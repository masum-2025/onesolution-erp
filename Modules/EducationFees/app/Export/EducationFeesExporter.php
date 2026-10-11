<?php

namespace Modules\EducationFees\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\EducationFees\Models\Advance;
use Modules\EducationFees\Models\Allocation;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Models\BillLine;
use Modules\EducationFees\Models\Concession;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Models\FeeRun;
use Modules\EducationFees\Models\FeeStructure;
use Modules\EducationFees\Models\FeeStructureLine;
use Modules\EducationFees\Models\Fine;
use Modules\EducationFees\Models\Receipt;
use Modules\EducationFees\Models\ReceiptVoid;
use Modules\EducationFees\Models\Refund;

/**
 * Student fees in the client's data export: heads, structures, concessions,
 * runs, every bill with its lines, fines, receipts, what they paid, advances,
 * voids and refunds.
 */
class EducationFeesExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'education_fees';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $plain = fn (Model $row, array $columns) => array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value, $row->only(['id', ...$columns]));

        return [
            'heads' => $this->rows(FeeHead::class, $organization, $organizationIds, fn ($row) => [...$plain($row, ['code', 'frequency', 'income_key', 'tax_code_id', 'sibling_discount', 'late_fine', 'is_active']), 'name' => $row->texts('name')]),
            'structures' => $this->rows(FeeStructure::class, $organization, $organizationIds, fn ($row) => $plain($row, ['name', 'session_id', 'unit_id', 'program_id', 'level_id', 'category_id', 'status'])),
            'structure_lines' => $this->rows(FeeStructureLine::class, $organization, $organizationIds, fn ($row) => $plain($row, ['structure_id', 'head_id', 'amount_minor', 'months'])),
            'concessions' => $this->rows(Concession::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'student_id', 'head_id', 'mode', 'percent_bp', 'amount_minor', 'reason', 'starts_on', 'ends_on', 'status', 'decided_at'])),
            'runs' => $this->rows(FeeRun::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'session_id', 'kind', 'period', 'level_id', 'section_id', 'issue_date', 'due_date', 'status', 'bills_count', 'total_minor'])),
            'bills' => $this->rows(Bill::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'student_id', 'session_id', 'run_id', 'billing_key', 'number', 'issue_date', 'due_date', 'status', 'currency', 'gross_minor', 'discount_minor', 'tax_minor', 'fine_minor', 'total_minor', 'paid_minor', 'source', 'cancel_reason'])),
            'bill_lines' => $this->rows(BillLine::class, $organization, $organizationIds, fn ($row) => $plain($row, ['bill_id', 'head_id', 'amount_minor', 'discount_minor', 'tax_minor', 'due_minor', 'tax_code_id', 'basis'])),
            'fines' => $this->rows(Fine::class, $organization, $organizationIds, fn ($row) => $plain($row, ['bill_id', 'kind', 'amount_minor', 'applied_on', 'reason'])),
            'receipts' => $this->rows(Receipt::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'student_id', 'number', 'received_on', 'method', 'reference', 'amount_minor', 'currency', 'status', 'note', 'collected_by', 'payment_id'])),
            'allocations' => $this->rows(Allocation::class, $organization, $organizationIds, fn ($row) => $plain($row, ['bill_id', 'kind', 'receipt_id', 'advance_id', 'reverses_id', 'amount_minor', 'created_at'])),
            'advances' => $this->rows(Advance::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'student_id', 'kind', 'amount_minor', 'receipt_id', 'bill_id', 'refund_id', 'note', 'created_at'])),
            'voids' => $this->rows(ReceiptVoid::class, $organization, $organizationIds, fn ($row) => $plain($row, ['receipt_id', 'reason', 'status', 'decided_at', 'decision_note'])),
            'refunds' => $this->rows(Refund::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'student_id', 'amount_minor', 'currency', 'method', 'reference', 'reason', 'status', 'decided_at'])),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $organizationIds
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
