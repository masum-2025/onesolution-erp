<?php

namespace Modules\Accounting\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Enums\DocumentType;
use Modules\Accounting\Enums\SettlementStatus;
use Modules\Accounting\Enums\SettlementType;
use Modules\Accounting\Models\Allocation;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Models\Settlement;

/**
 * Who owes what, on any day: open invoices (bills) by how long they are
 * overdue (rule accounting.aging_buckets), less unused credits and money
 * not yet allocated; and a party's statement over a range. Voided records
 * are left out as if they never existed. Sums are made here, not in SQL.
 */
class Receivables
{
    public function __construct(
        private Books $books,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @param  'sales'|'purchases'  $side
     * @return array<string, mixed>
     */
    public function aging(Organization $company, string $side, string $asOf): array
    {
        $owed = $side === 'sales' ? DocumentType::Invoice : DocumentType::Bill;
        $credit = $side === 'sales' ? DocumentType::CreditNote : DocumentType::VendorCredit;
        $money = $side === 'sales' ? SettlementType::Receipt : SettlementType::Payment;
        $day = CarbonImmutable::parse($asOf, 'UTC');

        $limits = array_values(array_map('intval', (array) $this->rules->get('accounting.aging_buckets', $this->contexts->forOrganization($company))));
        sort($limits);
        $bucketKeys = ['current', ...array_map(fn (int $limit) => "upto_{$limit}", $limits), 'over_'.($limits === [] ? 0 : end($limits))];

        $rows = [];
        $row = function (string $partyId) use (&$rows, $bucketKeys) {
            return $rows[$partyId] ??= ['party_id' => $partyId, 'buckets' => array_fill_keys($bucketKeys, 0), 'credits_minor' => 0];
        };

        foreach ($this->documents($company, [$owed, $credit], $asOf) as $document) {
            // What was paid on an invoice by then, or how much of a credit was used by then.
            $open = $document->total_minor - $this->allocatedBy($company, $document->type->isCredit() ? 'credit_document_id' : 'document_id', $document->getKey(), $asOf);
            if ($open <= 0) {
                continue;
            }
            $row($document->party_id);
            if ($document->type->isCredit()) {
                $rows[$document->party_id]['credits_minor'] += $open;

                continue;
            }
            $late = (int) $document->due_date->diffInDays($day, false);
            $rows[$document->party_id]['buckets'][$this->bucket($late, $limits, $bucketKeys)] += $open;
        }

        foreach ($this->settlements($company, $money, $asOf) as $settlement) {
            $left = $settlement->amount_minor - $this->allocatedBy($company, 'settlement_id', $settlement->getKey(), $asOf);
            if ($left > 0) {
                $row($settlement->party_id);
                $rows[$settlement->party_id]['credits_minor'] += $left;
            }
        }

        $names = $this->books->query(Party::class, $company)->whereKey(array_keys($rows))->pluck('name', 'id');
        $totals = ['buckets' => array_fill_keys($bucketKeys, 0), 'credits_minor' => 0, 'net_minor' => 0];
        $result = [];
        foreach ($rows as $partyId => $data) {
            $net = array_sum($data['buckets']) - $data['credits_minor'];
            $result[] = [...$data, 'party_name' => $names[$partyId] ?? '', 'net_minor' => $net];
            foreach ($data['buckets'] as $key => $amount) {
                $totals['buckets'][$key] += $amount;
            }
            $totals['credits_minor'] += $data['credits_minor'];
            $totals['net_minor'] += $net;
        }
        usort($result, fn (array $a, array $b) => strcmp($a['party_name'], $b['party_name']));

        return [
            'side' => $side,
            'as_of' => $asOf,
            'currency' => $this->books->currency($company),
            'buckets' => $bucketKeys,
            'bucket_limits' => $limits,
            'rows' => $result,
            'totals' => $totals,
        ];
    }

    /**
     * Everything with one party on one side between two days: opening
     * balance, each document, credit and settlement with the running
     * balance (what the party owes, or is owed), closing balance.
     *
     * @param  'sales'|'purchases'  $side
     * @return array<string, mixed>
     */
    public function statement(Organization $company, Party $party, string $side, string $from, string $to): array
    {
        $types = $side === 'sales' ? [DocumentType::Invoice, DocumentType::CreditNote] : [DocumentType::Bill, DocumentType::VendorCredit];
        $money = $side === 'sales' ? SettlementType::Receipt : SettlementType::Payment;

        $entries = collect();
        foreach ($this->documents($company, $types, $to, $party->getKey()) as $document) {
            $entries->push([
                'date' => $document->issue_date->toDateString(),
                'kind' => $document->type->value,
                'id' => $document->getKey(),
                'number' => $document->number,
                'reference' => $document->reference,
                'due_date' => $document->type->isCredit() ? null : $document->due_date->toDateString(),
                'amount_minor' => $document->type->isCredit() ? -$document->total_minor : $document->total_minor,
                'order' => $document->posted_at?->getTimestamp() ?? 0,
            ]);
        }
        foreach ($this->settlements($company, $money, $to, $party->getKey()) as $settlement) {
            $entries->push([
                'date' => $settlement->settled_on->toDateString(),
                'kind' => $settlement->type->value,
                'id' => $settlement->getKey(),
                'number' => $settlement->number,
                'reference' => $settlement->reference,
                'due_date' => null,
                'amount_minor' => -$settlement->amount_minor,
                'order' => $settlement->posted_at?->getTimestamp() ?? 0,
            ]);
        }

        $sorted = $entries->sortBy([['date', 'asc'], ['order', 'asc']])->values();
        $opening = (int) $sorted->filter(fn (array $entry) => $entry['date'] < $from)->sum('amount_minor');
        $balance = $opening;
        $rows = [];
        foreach ($sorted->filter(fn (array $entry) => $entry['date'] >= $from) as $entry) {
            $balance += $entry['amount_minor'];
            unset($entry['order']);
            $rows[] = [...$entry, 'balance_minor' => $balance];
        }

        return [
            'party' => ['id' => $party->getKey(), 'name' => $party->name, 'code' => $party->code],
            'side' => $side,
            'from' => $from,
            'to' => $to,
            'currency' => $this->books->currency($company),
            'opening_minor' => $opening,
            'rows' => $rows,
            'closing_minor' => $balance,
        ];
    }

    /** What a party owes now (positive) or has paid ahead (negative), on one side. */
    public function balanceOf(Organization $company, Party $party, string $side): int
    {
        $today = $this->books->today($company)->toDateString();

        return (int) collect($this->statement($company, $party, $side, $today, $today))->get('closing_minor');
    }

    /**
     * Posted (not voided) documents issued by a day.
     *
     * @param  list<DocumentType>  $types
     * @return Collection<int, Document>
     */
    private function documents(Organization $company, array $types, string $asOf, ?string $partyId = null): Collection
    {
        return $this->books->query(Document::class, $company)
            ->whereIn('type', array_map(fn (DocumentType $type) => $type->value, $types))
            ->whereIn('status', [DocumentStatus::Posted->value, DocumentStatus::PartlyPaid->value, DocumentStatus::Paid->value])
            ->where('issue_date', '<=', $asOf)
            ->when($partyId !== null, fn ($query) => $query->where('party_id', $partyId))
            ->get();
    }

    /**
     * @return Collection<int, Settlement>
     */
    private function settlements(Organization $company, SettlementType $type, string $asOf, ?string $partyId = null): Collection
    {
        return $this->books->query(Settlement::class, $company)
            ->where('type', $type->value)
            ->where('status', SettlementStatus::Posted->value)
            ->where('settled_on', '<=', $asOf)
            ->when($partyId !== null, fn ($query) => $query->where('party_id', $partyId))
            ->get();
    }

    /** Live allocations by one column (what was paid, or what paid) up to a day. */
    private function allocatedBy(Organization $company, string $column, string $id, string $asOf): int
    {
        return (int) $this->books->query(Allocation::class, $company)
            ->where($column, $id)
            ->whereNull('voided_at')
            ->where('allocated_on', '<=', $asOf)
            ->sum('amount_minor');
    }

    /**
     * @param  list<int>  $limits
     * @param  list<string>  $keys
     */
    private function bucket(int $daysLate, array $limits, array $keys): string
    {
        if ($daysLate <= 0) {
            return 'current';
        }
        foreach ($limits as $index => $limit) {
            if ($daysLate <= $limit) {
                return $keys[$index + 1];
            }
        }

        return end($keys);
    }
}
