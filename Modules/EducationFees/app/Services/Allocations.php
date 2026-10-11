<?php

namespace Modules\EducationFees\Services;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\EducationFees\Models\Advance;
use Modules\EducationFees\Models\Allocation;
use Modules\EducationFees\Models\Bill;

/**
 * Money meeting bills: each allocation row says how much of a receipt or
 * of the student's advance went to a bill, and keeps the bill's paid amount
 * and status (open, paid) in step. Undoing adds negative rows that point at
 * what they undo. Call inside the caller's transaction with the bill locked.
 */
class Allocations
{
    public function __construct(private FeeOffice $office) {}

    /** @param  array{receipt_id?: ?string, advance_id?: ?string, reverses_id?: ?string}  $refs */
    public function add(Organization $company, Bill $bill, string $kind, int $amountMinor, array $refs, ?string $actorId): Allocation
    {
        $allocation = new Allocation;
        $allocation->fill([
            'organization_id' => $company->getKey(), 'bill_id' => $bill->getKey(), 'kind' => $kind, 'amount_minor' => $amountMinor,
            'receipt_id' => $refs['receipt_id'] ?? null, 'advance_id' => $refs['advance_id'] ?? null, 'reverses_id' => $refs['reverses_id'] ?? null,
            'created_by' => $actorId,
        ]);
        $allocation->save();
        $paid = $bill->paid_minor + $amountMinor;
        $bill->forceFill([
            'paid_minor' => $paid,
            'status' => $bill->status === 'cancelled' ? 'cancelled' : ($paid >= $bill->total_minor ? 'paid' : 'open'),
            'version' => $bill->version + 1,
        ])->save();

        return $allocation;
    }

    /**
     * What still stands of each allocation (its amount less what undid it),
     * for a receipt or a bill.
     *
     * @return Collection<int, array{allocation: Allocation, standing: int}>
     */
    public function standing(Organization $company, ?string $receiptId = null, ?string $billId = null): Collection
    {
        $rows = $this->office->query(Allocation::class, $company)
            ->when($receiptId !== null, fn ($query) => $query->where('receipt_id', $receiptId))
            ->when($billId !== null, fn ($query) => $query->where('bill_id', $billId))
            ->orderBy('created_at')->orderBy('id')->get();
        $undone = $this->office->query(Allocation::class, $company)->where('kind', 'reversal')->whereIn('reverses_id', $rows->pluck('id'))
            ->get()->groupBy('reverses_id')->map(fn ($group) => (int) $group->sum('amount_minor'));

        return $rows->where('kind', '!=', 'reversal')
            ->map(fn (Allocation $row) => ['allocation' => $row, 'standing' => $row->amount_minor + ($undone[$row->getKey()] ?? 0)])
            ->filter(fn (array $row) => $row['standing'] > 0)->values();
    }

    /** Undo what still stands of an allocation on its (locked) bill. */
    public function undo(Organization $company, Bill $bill, Allocation $allocation, int $standing, ?string $actorId): void
    {
        $this->add($company, $bill, 'reversal', -$standing, [
            'receipt_id' => $allocation->receipt_id, 'advance_id' => $allocation->advance_id, 'reverses_id' => $allocation->getKey(),
        ], $actorId);
    }

    /**
     * A student's advance: the money held for them now.
     */
    public function advanceBalance(Organization $company, string $studentId, bool $lock = false): int
    {
        $query = $this->office->query(Advance::class, $company)->where('student_id', $studentId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return (int) $query->get(['amount_minor'])->sum('amount_minor');
    }

    /** @param  array{receipt_id?: ?string, bill_id?: ?string, refund_id?: ?string}  $refs */
    public function moveAdvance(Organization $company, string $unitId, string $studentId, string $kind, int $amountMinor, array $refs, ?string $note, ?string $actorId): Advance
    {
        $advance = new Advance;
        $advance->fill([
            'organization_id' => $company->getKey(), 'unit_id' => $unitId, 'student_id' => $studentId, 'kind' => $kind, 'amount_minor' => $amountMinor,
            'receipt_id' => $refs['receipt_id'] ?? null, 'bill_id' => $refs['bill_id'] ?? null, 'refund_id' => $refs['refund_id'] ?? null,
            'note' => $note, 'created_by' => $actorId,
        ]);
        $advance->save();

        return $advance;
    }

    /** The student's advance meets what is still owed on a (locked, open) bill; how much it took. */
    public function applyAdvance(Organization $company, Bill $bill, ?string $actorId): int
    {
        $available = $this->advanceBalance($company, $bill->student_id, lock: true);
        $use = min($available, $bill->balanceMinor());
        if ($use <= 0 || $bill->status !== 'open') {
            return 0;
        }
        $advance = $this->moveAdvance($company, $bill->unit_id, $bill->student_id, 'applied', -$use, ['bill_id' => $bill->getKey()], null, $actorId);
        $this->add($company, $bill, 'advance', $use, ['advance_id' => $advance->getKey()], $actorId);

        return $use;
    }
}
