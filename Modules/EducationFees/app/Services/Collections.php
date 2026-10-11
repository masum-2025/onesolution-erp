<?php

namespace Modules\EducationFees\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\EducationFees\Events\FeePaid;
use Modules\EducationFees\Events\ReceiptVoided;
use Modules\EducationFees\Exceptions\FeeException;
use Modules\EducationFees\Models\Advance;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Models\Receipt;
use Modules\EducationFees\Models\ReceiptVoid;
use Modules\EducationFees\Models\Refund;

/**
 * Taking fees and undoing them.
 *
 * - A receipt meets the student's open bills, oldest due first (or the
 *   bills and amounts the office names); more than they owe waits as an
 *   advance. Part payments only when rule education_fees.allow_partial_payment
 *   allows them; methods from rule education_fees.payment_methods.
 * - A receipt is never changed: it is voided with a reason, by another
 *   person when rule education_fees.void_needs_second_person is on (never
 *   the one who took it or asked). What it paid becomes owed again; its
 *   advance goes too (refused while that advance was already used).
 * - A student's advance is paid back by a refund: asked with a reason,
 *   decided like a void.
 */
class Collections
{
    public function __construct(
        private FeeOffice $office,
        private Allocations $allocations,
        private FeeNumbers $numbers,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private OrganizationSettingsResolver $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  student_id, unit_id, amount_minor, method, reference?, received_on, note?, op_id?, allocations? [{bill_id, amount_minor}]
     */
    public function collect(Organization $company, array $data, ?User $actor, ?string $paymentId = null): Receipt
    {
        if (($data['op_id'] ?? null) !== null && ($done = $this->office->query(Receipt::class, $company)->where('op_id', $data['op_id'])->first()) !== null) {
            return $done;
        }
        $unit = Organization::query()->find($data['unit_id']) ?? $company;
        $methods = (array) $this->rules->get('education_fees.payment_methods', $this->contexts->forOrganization($unit));
        if ($data['method'] !== 'online' && ! in_array($data['method'], $methods, true)) {
            throw FeeException::methodNotAllowed($data['method']);
        }
        $partial = (bool) $this->rules->get('education_fees.allow_partial_payment', $this->contexts->forOrganization($unit));

        $receipt = $this->office->transaction($company, function () use ($company, $data, $actor, $paymentId, $partial) {
            $bills = $this->office->query(Bill::class, $company)->where('student_id', $data['student_id'])->where('status', 'open')
                ->orderBy('due_date')->orderBy('number')->lockForUpdate()->get()->keyBy('id');
            $plan = $this->plan($bills, (int) $data['amount_minor'], $data['allocations'] ?? null, $partial);

            $receipt = new Receipt;
            $receipt->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $data['unit_id'], 'student_id' => $data['student_id'],
                'number' => $this->numbers->receipt($company, (int) substr($data['received_on'], 0, 4)), 'received_on' => $data['received_on'],
                'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'amount_minor' => (int) $data['amount_minor'],
                'currency' => $this->currency($company), 'status' => 'valid', 'note' => $data['note'] ?? null, 'collected_by' => $actor?->getKey(),
                'payment_id' => $paymentId, 'op_id' => $data['op_id'] ?? null, 'version' => 1,
            ]);
            $receipt->save();
            $used = 0;
            foreach ($plan as $billId => $amount) {
                $this->allocations->add($company, $bills[$billId], 'receipt', $amount, ['receipt_id' => $receipt->getKey()], $actor?->getKey());
                $used += $amount;
            }
            if ($receipt->amount_minor > $used) {
                $this->allocations->moveAdvance($company, $receipt->unit_id, $receipt->student_id, 'overpaid', $receipt->amount_minor - $used, ['receipt_id' => $receipt->getKey()], null, $actor?->getKey());
            }
            $this->audit->record('education_fees.receipt_taken', $receipt, new: [
                ...$receipt->only(['number', 'student_id', 'method', 'amount_minor', 'received_on']), 'bills' => $plan, 'advance_minor' => $receipt->amount_minor - $used,
            ], actor: $actor, organizationId: $company->getKey());
            DB::afterCommit(fn () => event(new FeePaid($company->getKey(), $receipt->getKey(), $receipt->student_id)));

            return $receipt;
        });

        return $receipt;
    }

    /** Ask to void a receipt; voided at once when the rule needs no second person. */
    public function requestVoid(Organization $company, Receipt $receipt, string $reason, User $actor): ReceiptVoid
    {
        return $this->office->transaction($company, function () use ($company, $receipt, $reason, $actor) {
            $receipt = $this->lockedReceipt($company, $receipt);
            if ($receipt->status !== 'valid') {
                throw FeeException::wrongStatus($receipt->status);
            }
            if ($this->office->query(ReceiptVoid::class, $company)->where('receipt_id', $receipt->getKey())->where('status', 'pending')->exists()) {
                throw FeeException::voidPending();
            }
            $void = new ReceiptVoid;
            $void->fill(['organization_id' => $company->getKey(), 'unit_id' => $receipt->unit_id, 'receipt_id' => $receipt->getKey(), 'reason' => $reason, 'status' => 'pending', 'requested_by' => $actor->getKey(), 'version' => 1]);
            $void->save();
            $this->audit->record('education_fees.void_requested', $receipt, new: ['number' => $receipt->number, 'amount_minor' => $receipt->amount_minor], reason: $reason, actor: $actor, organizationId: $company->getKey());
            if (! $this->secondPerson($company, $receipt)) {
                $this->void($company, $receipt, $void, $actor, null);
            }

            return $void->refresh();
        });
    }

    public function decideVoid(Organization $company, ReceiptVoid $void, bool $approve, ?string $note, int $baseVersion, User $actor): ReceiptVoid
    {
        return $this->office->transaction($company, function () use ($company, $void, $approve, $note, $baseVersion, $actor) {
            /** @var ReceiptVoid $void */
            $void = $this->office->query(ReceiptVoid::class, $company)->whereKey($void->getKey())->lockForUpdate()->firstOrFail();
            $this->assertDecidable($void->status, $void->version, $baseVersion);
            $receipt = $this->lockedReceipt($company, $this->office->query(Receipt::class, $company)->findOrFail($void->receipt_id));
            if (in_array($actor->getKey(), [$void->requested_by, $receipt->collected_by], true)) {
                throw FeeException::ownApproval();
            }
            if ($approve) {
                $this->void($company, $receipt, $void, $actor, $note);
            } else {
                $void->forceFill(['status' => 'rejected', 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note, 'version' => $void->version + 1])->save();
                $this->audit->record('education_fees.void_rejected', $receipt, reason: $note, actor: $actor, organizationId: $company->getKey());
            }

            return $void;
        });
    }

    /** Ask to pay back part of a student's advance; paid at once when the rule needs no second person. */
    public function requestRefund(Organization $company, array $data, User $actor): Refund
    {
        return $this->office->transaction($company, function () use ($company, $data, $actor) {
            $available = $this->allocations->advanceBalance($company, $data['student_id'], lock: true);
            if ((int) $data['amount_minor'] > $available) {
                throw FeeException::advanceTooSmall($available);
            }
            $refund = new Refund;
            $refund->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $data['unit_id'], 'student_id' => $data['student_id'], 'amount_minor' => (int) $data['amount_minor'],
                'currency' => $this->currency($company), 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'reason' => $data['reason'],
                'status' => 'pending', 'requested_by' => $actor->getKey(), 'version' => 1,
            ]);
            $refund->save();
            $this->audit->record('education_fees.refund_requested', $refund, new: $refund->only(['student_id', 'amount_minor', 'method']), reason: $refund->reason, actor: $actor, organizationId: $company->getKey());
            if (! $this->secondPerson($company, null, $refund->unit_id)) {
                $this->payBack($company, $refund, $actor, null);
            }

            return $refund->refresh();
        });
    }

    public function decideRefund(Organization $company, Refund $refund, bool $approve, ?string $note, int $baseVersion, User $actor): Refund
    {
        return $this->office->transaction($company, function () use ($company, $refund, $approve, $note, $baseVersion, $actor) {
            /** @var Refund $refund */
            $refund = $this->office->query(Refund::class, $company)->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();
            $this->assertDecidable($refund->status, $refund->version, $baseVersion);
            if ($refund->requested_by === $actor->getKey()) {
                throw FeeException::ownApproval();
            }
            if ($approve) {
                $this->payBack($company, $refund, $actor, $note);
            } else {
                $refund->forceFill(['status' => 'rejected', 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note, 'version' => $refund->version + 1])->save();
                $this->audit->record('education_fees.refund_rejected', $refund, reason: $note, actor: $actor, organizationId: $company->getKey());
            }

            return $refund;
        });
    }

    /**
     * What came in between two days at the unit and below: totals by method
     * and by the person who took it, voided receipts apart.
     *
     * @param  list<string>  $unitIds
     * @return array{receipts: Collection<int, Receipt>, by_method: array<string, int>, by_collector: array<string, int>, total_minor: int, voided_minor: int}
     */
    public function summary(Organization $company, array $unitIds, string $from, string $to, ?string $collectorId = null): array
    {
        $receipts = $this->office->query(Receipt::class, $company)->whereIn('unit_id', $unitIds)->whereBetween('received_on', [$from, $to])
            ->when($collectorId !== null, fn ($query) => $query->where('collected_by', $collectorId))
            ->orderBy('received_on')->orderBy('number')->get();
        $valid = $receipts->where('status', 'valid');

        return [
            'receipts' => $receipts,
            'by_method' => $valid->groupBy('method')->map(fn ($rows) => (int) $rows->sum('amount_minor'))->all(),
            'by_collector' => $valid->groupBy(fn (Receipt $receipt) => $receipt->collected_by ?? 'online')->map(fn ($rows) => (int) $rows->sum('amount_minor'))->all(),
            'total_minor' => (int) $valid->sum('amount_minor'),
            'voided_minor' => (int) $receipts->where('status', 'voided')->sum('amount_minor'),
        ];
    }

    /**
     * Which open bills an amount meets: the office's choice, or oldest due
     * first. Without part payments, a bill is met in full or not at all.
     *
     * @param  Collection<string, Bill>  $bills
     * @param  list<array{bill_id: string, amount_minor: int}>|null  $chosen
     * @return array<string, int> Bill id => amount.
     */
    private function plan(Collection $bills, int $amount, ?array $chosen, bool $partial): array
    {
        $plan = [];
        if ($chosen !== null) {
            $total = 0;
            foreach ($chosen as $row) {
                $bill = $bills[$row['bill_id']] ?? throw FeeException::notFound('bill');
                $part = (int) $row['amount_minor'];
                if ($part > $bill->balanceMinor()) {
                    throw FeeException::moreThanOwed($bill->number ?? '');
                }
                if (! $partial && $part < $bill->balanceMinor()) {
                    throw FeeException::partialNotAllowed($bill->number ?? '');
                }
                $plan[$bill->getKey()] = $part;
                $total += $part;
            }
            if ($total > $amount) {
                throw FeeException::allocationsExceedAmount();
            }

            return $plan;
        }
        $left = $amount;
        foreach ($bills as $bill) {
            $owed = $bill->balanceMinor();
            if ($left <= 0 || $owed <= 0) {
                continue;
            }
            if (! $partial && $left < $owed) {
                if ($plan === []) {
                    throw FeeException::partialNotAllowed($bill->number ?? '');
                }
                break;
            }
            $plan[$bill->getKey()] = min($left, $owed);
            $left -= $plan[$bill->getKey()];
        }

        return $plan;
    }

    private function void(Organization $company, Receipt $receipt, ReceiptVoid $void, User $actor, ?string $note): void
    {
        foreach ($this->allocations->standing($company, receiptId: $receipt->getKey()) as $row) {
            $bill = $this->office->query(Bill::class, $company)->whereKey($row['allocation']->bill_id)->lockForUpdate()->firstOrFail();
            $this->allocations->undo($company, $bill, $row['allocation'], $row['standing'], $actor->getKey());
        }
        $held = (int) $this->office->query(Advance::class, $company)->where('receipt_id', $receipt->getKey())->sum('amount_minor');
        if ($held > 0) {
            $available = $this->allocations->advanceBalance($company, $receipt->student_id, lock: true);
            if ($available < $held) {
                throw FeeException::advanceAlreadyUsed();
            }
            $this->allocations->moveAdvance($company, $receipt->unit_id, $receipt->student_id, 'voided', -$held, ['receipt_id' => $receipt->getKey()], $void->reason, $actor->getKey());
        }
        $receipt->forceFill(['status' => 'voided', 'version' => $receipt->version + 1])->save();
        $void->forceFill(['status' => 'approved', 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note, 'version' => $void->version + 1])->save();
        $this->audit->record('education_fees.receipt_voided', $receipt, old: ['status' => 'valid'], new: ['status' => 'voided', 'amount_minor' => $receipt->amount_minor], reason: $void->reason, actor: $actor, organizationId: $company->getKey());
        DB::afterCommit(fn () => event(new ReceiptVoided($company->getKey(), $receipt->getKey(), $receipt->student_id)));
    }

    private function payBack(Organization $company, Refund $refund, User $actor, ?string $note): void
    {
        $available = $this->allocations->advanceBalance($company, $refund->student_id, lock: true);
        if ($refund->amount_minor > $available) {
            throw FeeException::advanceTooSmall($available);
        }
        $this->allocations->moveAdvance($company, $refund->unit_id, $refund->student_id, 'refunded', -$refund->amount_minor, ['refund_id' => $refund->getKey()], $refund->reason, $actor->getKey());
        $refund->forceFill(['status' => 'approved', 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note, 'version' => $refund->version + 1])->save();
        $this->audit->record('education_fees.refund_paid', $refund, new: $refund->only(['student_id', 'amount_minor', 'method']), reason: $refund->reason, actor: $actor, organizationId: $company->getKey());
    }

    private function secondPerson(Organization $company, ?Receipt $receipt, ?string $unitId = null): bool
    {
        $unit = Organization::query()->find($receipt?->unit_id ?? $unitId) ?? $company;

        return (bool) $this->rules->get('education_fees.void_needs_second_person', $this->contexts->forOrganization($unit));
    }

    private function assertDecidable(string $status, int $version, int $baseVersion): void
    {
        if ($version !== $baseVersion) {
            throw FeeException::versionConflict(['version' => $version]);
        }
        if ($status !== 'pending') {
            throw FeeException::wrongStatus($status);
        }
    }

    private function lockedReceipt(Organization $company, Receipt $receipt): Receipt
    {
        /** @var Receipt $fresh */
        $fresh = $this->office->query(Receipt::class, $company)->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

        return $fresh;
    }

    private function currency(Organization $company): string
    {
        return $this->settings->values($company)['currency_code'] ?? throw FeeException::noCurrency();
    }
}
