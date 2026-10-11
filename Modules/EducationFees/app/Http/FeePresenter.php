<?php

namespace Modules\EducationFees\Http;

use Illuminate\Support\Collection;
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
 * What the fee API shows: money as integer minor units with the currency,
 * dates as Y-m-d, names in every language (the screen picks one).
 */
class FeePresenter
{
    /** @return array<string, mixed> */
    public function head(FeeHead $head): array
    {
        return [
            'id' => $head->getKey(),
            ...$head->only(['code', 'frequency', 'income_key', 'tax_code_id', 'sibling_discount', 'late_fine', 'is_active', 'sort_order', 'version']),
            'name' => $head->texts('name'),
        ];
    }

    /**
     * @param  Collection<int, FeeStructureLine>|null  $lines
     * @return array<string, mixed>
     */
    public function structure(FeeStructure $structure, ?Collection $lines = null): array
    {
        return [
            'id' => $structure->getKey(),
            ...$structure->only(['name', 'session_id', 'unit_id', 'program_id', 'level_id', 'category_id', 'status', 'version']),
            ...($lines === null ? [] : [
                'lines' => $lines->map(fn (FeeStructureLine $line) => $line->only(['head_id', 'amount_minor', 'months']))->values(),
                'total_minor' => (int) $lines->sum('amount_minor'),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $student
     * @return array<string, mixed>
     */
    public function concession(Concession $concession, ?array $student = null): array
    {
        return [
            'id' => $concession->getKey(),
            ...$concession->only(['unit_id', 'student_id', 'head_id', 'mode', 'percent_bp', 'amount_minor', 'reason', 'status', 'requested_by', 'decided_by', 'decision_note', 'version']),
            'starts_on' => $concession->starts_on->toDateString(),
            'ends_on' => $concession->ends_on?->toDateString(),
            'decided_at' => $concession->decided_at?->toIso8601String(),
            'student' => $this->student($student),
        ];
    }

    /** @return array<string, mixed> */
    public function run(FeeRun $run): array
    {
        return [
            'id' => $run->getKey(),
            ...$run->only(['unit_id', 'session_id', 'kind', 'period', 'level_id', 'section_id', 'head_ids', 'status', 'bills_count', 'total_minor', 'note', 'created_by', 'finalized_by', 'version']),
            'issue_date' => $run->issue_date->toDateString(),
            'due_date' => $run->due_date->toDateString(),
            'finalized_at' => $run->finalized_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, BillLine>|null  $lines
     * @param  Collection<int, Fine>|null  $fines
     * @param  array<string, mixed>|null  $student
     * @return array<string, mixed>
     */
    public function bill(Bill $bill, ?Collection $lines = null, ?Collection $fines = null, ?array $student = null, ?string $today = null): array
    {
        return [
            'id' => $bill->getKey(),
            ...$bill->only(['unit_id', 'student_id', 'session_id', 'run_id', 'billing_key', 'number', 'status', 'currency', 'gross_minor', 'discount_minor', 'tax_minor', 'fine_minor', 'total_minor', 'paid_minor', 'source', 'cancel_reason', 'version']),
            'balance_minor' => $bill->status === 'cancelled' ? 0 : $bill->balanceMinor(),
            'issue_date' => $bill->issue_date->toDateString(),
            'due_date' => $bill->due_date->toDateString(),
            'overdue' => $today !== null && $bill->status === 'open' && $bill->due_date->toDateString() < $today && $bill->balanceMinor() > 0,
            'fines_stopped' => $bill->fines_stopped_at !== null,
            'student' => $this->student($student),
            ...($lines === null ? [] : ['lines' => $lines->map(fn (BillLine $line) => $line->only(['id', 'head_id', 'amount_minor', 'discount_minor', 'tax_minor', 'due_minor', 'tax_code_id', 'basis']))->values()]),
            ...($fines === null ? [] : ['fines' => $fines->map(fn (Fine $fine) => [...$fine->only(['id', 'kind', 'amount_minor', 'reason']), 'applied_on' => $fine->applied_on->toDateString()])->values()]),
        ];
    }

    /**
     * @param  list<array{bill_id: string, number: ?string, amount_minor: int}>|null  $paid  What the receipt paid, bill by bill (still standing).
     * @param  array<string, mixed>|null  $student
     * @return array<string, mixed>
     */
    public function receipt(Receipt $receipt, ?array $paid = null, ?int $advanceMinor = null, ?array $student = null): array
    {
        return [
            'id' => $receipt->getKey(),
            ...$receipt->only(['unit_id', 'student_id', 'number', 'method', 'reference', 'amount_minor', 'currency', 'status', 'note', 'collected_by', 'payment_id', 'version']),
            'received_on' => $receipt->received_on->toDateString(),
            'created_at' => $receipt->created_at?->toIso8601String(),
            'student' => $this->student($student),
            ...($paid === null ? [] : ['bills' => $paid, 'advance_minor' => $advanceMinor]),
        ];
    }

    /** @return array<string, mixed> */
    public function receiptVoid(ReceiptVoid $void, ?Receipt $receipt = null): array
    {
        return [
            'id' => $void->getKey(),
            ...$void->only(['receipt_id', 'reason', 'status', 'requested_by', 'decided_by', 'decision_note', 'version']),
            'decided_at' => $void->decided_at?->toIso8601String(),
            'created_at' => $void->created_at?->toIso8601String(),
            'receipt' => $receipt === null ? null : $receipt->only(['number', 'student_id', 'amount_minor', 'method', 'collected_by']) + ['received_on' => $receipt->received_on->toDateString()],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $student
     * @return array<string, mixed>
     */
    public function refund(Refund $refund, ?array $student = null): array
    {
        return [
            'id' => $refund->getKey(),
            ...$refund->only(['unit_id', 'student_id', 'amount_minor', 'currency', 'method', 'reference', 'reason', 'status', 'requested_by', 'decided_by', 'decision_note', 'version']),
            'decided_at' => $refund->decided_at?->toIso8601String(),
            'created_at' => $refund->created_at?->toIso8601String(),
            'student' => $this->student($student),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $student
     * @return array<string, mixed>|null
     */
    private function student(?array $student): ?array
    {
        return $student === null ? null : array_intersect_key($student, array_flip(['id', 'code', 'name', 'name_local', 'unit_id', 'status']));
    }
}
