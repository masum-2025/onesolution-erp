<?php

namespace Modules\EducationFees\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\Ledger\LedgerEntry;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Services\Ledger;
use Modules\EducationFees\Jobs\PostFees;
use Modules\EducationFees\Models\Advance;
use Modules\EducationFees\Models\Allocation;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Models\BillLine;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Models\Fine;
use Modules\EducationFees\Models\Posting;
use Modules\EducationFees\Models\Receipt;
use Modules\EducationFees\Models\ReceiptVoid;
use Modules\EducationFees\Models\Refund;

/**
 * Fees into the books, where the institution keeps them (Accounting on),
 * through Accounting's public Ledger.
 *
 * Each money event leaves a row in fee_postings in its own transaction; a
 * queued job posts it later (op id "fee-<kind>-<id>": twice is once), so
 * the counter never waits for the books and a closed period or a missing
 * account only leaves the row "failed" with its reason, tried again by
 * education-fees:post-pending. Entries:
 *
 * - bill:    Dr receivable (owed) + Dr discounts / Cr income per head + Cr tax
 * - fine:    Dr receivable / Cr fine income (a waiver the other way)
 * - receipt: Dr cash|bank|mobile|online / Cr receivable (+ Cr advance for more than owed)
 * - advance: used on a bill: Dr advance / Cr receivable; fewer credits: Dr head income / Cr advance
 * - void:    the receipt's entry undone (what it still paid)
 * - cancel:  the bill's entry and fines undone; what was paid on it Dr receivable / Cr advance
 * - refund:  Dr advance / Cr cash|bank|mobile
 */
class FeePostings
{
    private const KEY = 'education_fees.';

    public function __construct(private FeeOffice $office, private ModuleResolver $modules) {}

    /** Note an event for the books (inside the caller's transaction); the job runs after it commits. */
    public function queue(Organization $company, string $kind, string $sourceId): void
    {
        if (! $this->modules->isEnabled('accounting', $company)) {
            return;
        }
        $exists = $this->office->query(Posting::class, $company)->where('kind', $kind)->where('source_id', $sourceId)->exists();
        if (! $exists) {
            (new Posting)->fill(['organization_id' => $company->getKey(), 'kind' => $kind, 'source_id' => $sourceId, 'status' => 'pending', 'attempts' => 0])->save();
        }
        DB::afterCommit(fn () => PostFees::dispatch($company->getKey()));
    }

    /** Post what waits (and what failed, when asked); how many reached the books. */
    public function run(Organization $company, bool $retryFailed = false): int
    {
        if (! $this->modules->isEnabled('accounting', $company)) {
            return 0;
        }
        $rows = $this->office->query(Posting::class, $company)->whereIn('status', $retryFailed ? ['pending', 'failed'] : ['pending'])
            ->orderBy('created_at')->orderBy('id')->limit(1000)->get();
        $count = 0;
        foreach ($rows as $row) {
            try {
                $entry = $this->entry($company, $row);
                $journal = $entry === null ? null : app(Ledger::class)->post($company, $entry);
                $row->forceFill(['status' => 'posted', 'journal_id' => $journal?->id, 'error' => null, 'posted_at' => now(), 'attempts' => $row->attempts + 1])->save();
                $this->remember($company, $row, $journal?->id);
                $count++;
            } catch (\Throwable $exception) {
                // The reason stays on the row (no personal data: codes, accounts and periods only).
                $row->forceFill(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 300), 'attempts' => $row->attempts + 1])->save();
                Log::warning('education_fees.posting_failed', ['company' => $company->getKey(), 'posting' => $row->getKey(), 'kind' => $row->kind]);
            }
        }

        return $count;
    }

    /** The entry an event makes in the books (null: nothing to post). */
    public function entry(Organization $company, Posting $row): ?LedgerEntry
    {
        return match ($row->kind) {
            'bill' => $this->billEntry($company, $this->office->query(Bill::class, $company)->findOrFail($row->source_id), false),
            'cancel' => $this->billEntry($company, $this->office->query(Bill::class, $company)->findOrFail($row->source_id), true),
            'fine' => $this->fineEntry($company, $this->office->query(Fine::class, $company)->findOrFail($row->source_id)),
            'receipt' => $this->receiptEntry($company, $this->office->query(Receipt::class, $company)->findOrFail($row->source_id), null),
            'void' => $this->voidEntry($company, $this->office->query(ReceiptVoid::class, $company)->findOrFail($row->source_id)),
            'advance' => $this->advanceEntry($company, $this->office->query(Advance::class, $company)->findOrFail($row->source_id)),
            'refund' => $this->refundEntry($company, $this->office->query(Refund::class, $company)->findOrFail($row->source_id)),
        };
    }

    /** A bill issued (or, cancelled, undone with its fines; what was paid on it goes to the advance). */
    private function billEntry(Organization $company, Bill $bill, bool $cancelled): ?LedgerEntry
    {
        $lines = $this->office->query(BillLine::class, $company)->where('bill_id', $bill->getKey())->get();
        $heads = $this->office->query(FeeHead::class, $company)->whereKey($lines->pluck('head_id')->unique())->get()->keyBy('id');
        $income = [];
        foreach ($lines as $line) {
            $key = $heads[$line->head_id]->income_key ?? self::KEY.'other_income';
            // What the head earned: what is owed plus its discount, less the tax inside.
            $income[$key] = ($income[$key] ?? 0) + $line->due_minor + $line->discount_minor - $line->tax_minor;
        }
        $owed = (int) $lines->sum('due_minor');
        $discount = (int) $lines->sum('discount_minor');
        $tax = (int) $lines->sum('tax_minor');
        $postings = [
            $this->side(! $cancelled, self::KEY.'receivable', $owed),
            $this->side(! $cancelled, self::KEY.'discounts', $discount),
            $this->side($cancelled, self::KEY.'tax_output', $tax),
            ...array_map(fn (string $key, int $amount) => $this->side($cancelled, $key, $amount), array_keys($income), array_values($income)),
        ];
        $day = $bill->issue_date->toDateString();
        if ($cancelled) {
            $fines = (int) $bill->fine_minor;
            $freed = (int) $this->office->query(Advance::class, $company)->where('bill_id', $bill->getKey())->where('kind', 'bill_cancelled')->sum('amount_minor');
            $postings[] = LedgerLine::debit(self::KEY.'fine_income', $fines);
            $postings[] = LedgerLine::credit(self::KEY.'receivable', $fines);
            $postings[] = LedgerLine::debit(self::KEY.'receivable', $freed);
            $postings[] = LedgerLine::credit(self::KEY.'advance', $freed);
            $day = $this->office->today($company)->toDateString();
        }

        return $this->make($company, $cancelled ? "fee-cancel-{$bill->getKey()}" : "fee-bill-{$bill->getKey()}", $day,
            ($cancelled ? 'Fee bill cancelled ' : 'Fee bill ').$bill->number, $cancelled ? 'bill_cancel' : 'bill', $bill->getKey(), $bill->currency, $postings);
    }

    private function fineEntry(Organization $company, Fine $fine): ?LedgerEntry
    {
        $bill = $this->office->query(Bill::class, $company)->findOrFail($fine->bill_id);
        $amount = abs($fine->amount_minor);
        $raise = $fine->amount_minor > 0;

        return $this->make($company, "fee-fine-{$fine->getKey()}", $fine->applied_on->toDateString(), ($raise ? 'Late fine ' : 'Late fine waived ').$bill->number,
            'fine', $fine->getKey(), $bill->currency, [$this->side($raise, self::KEY.'receivable', $amount), $this->side(! $raise, self::KEY.'fine_income', $amount)]);
    }

    /** A receipt: what met bills and what waited as an advance (as first taken, before any void). */
    private function receiptEntry(Organization $company, Receipt $receipt, ?ReceiptVoid $void): ?LedgerEntry
    {
        $toBills = (int) $this->office->query(Allocation::class, $company)->where('receipt_id', $receipt->getKey())->where('kind', 'receipt')->sum('amount_minor');
        $toAdvance = (int) $this->office->query(Advance::class, $company)->where('receipt_id', $receipt->getKey())->where('kind', 'overpaid')->sum('amount_minor');
        $undo = $void !== null;
        if ($undo) {
            // Only what the void took back: bills it still paid, and the advance it still held.
            $toBills = (int) -$this->office->query(Allocation::class, $company)->where('receipt_id', $receipt->getKey())->where('kind', 'reversal')->sum('amount_minor');
            $toAdvance = (int) -$this->office->query(Advance::class, $company)->where('receipt_id', $receipt->getKey())->where('kind', 'voided')->sum('amount_minor');
        }
        $money = self::KEY.$receipt->method;

        return $this->make($company, $undo ? "fee-void-{$void->getKey()}" : "fee-receipt-{$receipt->getKey()}",
            $undo ? ($void->decided_at ?? now())->toDateString() : $receipt->received_on->toDateString(),
            ($undo ? 'Fee receipt voided ' : 'Fee receipt ').$receipt->number, $undo ? 'receipt_void' : 'receipt', $receipt->getKey(), $receipt->currency, [
                $this->side(! $undo, $money, $toBills + $toAdvance),
                $this->side($undo, self::KEY.'receivable', $toBills),
                $this->side($undo, self::KEY.'advance', $toAdvance),
            ]);
    }

    private function voidEntry(Organization $company, ReceiptVoid $void): ?LedgerEntry
    {
        return $this->receiptEntry($company, $this->office->query(Receipt::class, $company)->findOrFail($void->receipt_id), $void);
    }

    private function advanceEntry(Organization $company, Advance $advance): ?LedgerEntry
    {
        $currency = $this->office->currency($company);
        if ($advance->kind === 'applied') {
            return $this->make($company, "fee-advance-{$advance->getKey()}", $advance->created_at->toDateString(), 'Fee advance used', 'advance', $advance->getKey(), (string) $currency, [
                LedgerLine::debit(self::KEY.'advance', -$advance->amount_minor), LedgerLine::credit(self::KEY.'receivable', -$advance->amount_minor),
            ]);
        }
        // Fewer credits: the head's income is taken back and waits as an advance.
        $key = $this->office->query(FeeHead::class, $company)->find($advance->head_id)?->income_key ?? self::KEY.'other_income';

        return $this->make($company, "fee-advance-{$advance->getKey()}", $advance->created_at->toDateString(), 'Fee credits reduced', 'advance', $advance->getKey(), (string) $currency, [
            LedgerLine::debit($key, $advance->amount_minor), LedgerLine::credit(self::KEY.'advance', $advance->amount_minor),
        ]);
    }

    private function refundEntry(Organization $company, Refund $refund): ?LedgerEntry
    {
        return $this->make($company, "fee-refund-{$refund->getKey()}", ($refund->decided_at ?? now())->toDateString(), 'Fee advance refunded', 'refund', $refund->getKey(), $refund->currency, [
            LedgerLine::debit(self::KEY.'advance', $refund->amount_minor), LedgerLine::credit(self::KEY.$refund->method, $refund->amount_minor),
        ]);
    }

    /** A debit when $debit, else a credit. */
    private function side(bool $debit, string $key, int $amount): LedgerLine
    {
        return $debit ? LedgerLine::debit($key, $amount) : LedgerLine::credit($key, $amount);
    }

    /** @param  list<LedgerLine>  $lines */
    private function make(Organization $company, string $opId, string $day, string $narration, string $type, string $id, string $currency, array $lines): ?LedgerEntry
    {
        $lines = array_values(array_filter($lines, fn (LedgerLine $line) => $line->debitMinor + $line->creditMinor > 0));

        return $lines === [] ? null : new LedgerEntry(opId: $opId, entryDate: $day, narration: trim($narration), sourceModule: 'education_fees', sourceType: $type, sourceId: $id, currency: $currency, lines: $lines);
    }

    /** The journal on the bill, receipt or refund it belongs to (bills and receipts show it). */
    private function remember(Organization $company, Posting $row, ?string $journalId): void
    {
        if ($journalId === null) {
            return;
        }
        match ($row->kind) {
            'bill' => $this->office->query(Bill::class, $company)->whereKey($row->source_id)->whereNull('journal_id')->update(['journal_id' => $journalId]),
            'receipt' => $this->office->query(Receipt::class, $company)->whereKey($row->source_id)->whereNull('journal_id')->update(['journal_id' => $journalId]),
            'refund' => $this->office->query(Refund::class, $company)->whereKey($row->source_id)->whereNull('journal_id')->update(['journal_id' => $journalId]),
            default => null,
        };
    }
}
