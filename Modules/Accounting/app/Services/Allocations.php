<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Enums\DocumentType;
use Modules\Accounting\Models\Allocation;
use Modules\Accounting\Models\Document;

/**
 * What pays which document: part of a settlement, or of a credit note /
 * vendor credit, set against an invoice or bill of the same party. Each
 * document row is locked while its settled amount changes, so two people
 * never pay the same balance twice. Call inside a transaction.
 */
class Allocations
{
    public function __construct(private Books $books) {}

    /**
     * Check what a person asked for before anything is written (form errors
     * point at allocations.N.*).
     *
     * @param  list<array{document_id: string, amount_minor: int}>  $requested
     * @return int The total asked for.
     */
    public function check(Organization $company, string $partyId, DocumentType $type, array $requested, string $field = 'allocations'): int
    {
        $documents = $this->books->query(Document::class, $company)->whereKey(array_column($requested, 'document_id'))->get()->keyBy('id');
        $errors = [];
        $seen = [];
        $total = 0;
        foreach (array_values($requested) as $index => $item) {
            $document = $documents[$item['document_id']] ?? null;
            $amount = (int) $item['amount_minor'];
            if ($document === null || $document->party_id !== $partyId || $document->type !== $type || ! $document->status->isPosted() || isset($seen[$document->getKey()])) {
                $errors["{$field}.{$index}.document_id"] = __('accounting::accounting.validation.allocation_document');
            } elseif ($amount <= 0 || $amount > $document->balance()) {
                $errors["{$field}.{$index}.amount_minor"] = __('accounting::accounting.validation.allocation_amount');
            }
            $seen[$item['document_id']] = true;
            $total += max($amount, 0);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $total;
    }

    /**
     * Set amounts against documents, from a settlement or a credit.
     *
     * @param  array{settlement_id?: string, credit_document_id?: string}  $source
     * @param  list<array{document_id: string, amount_minor: int}>  $requested
     * @return int The total allocated.
     */
    public function apply(Organization $company, array $source, array $requested, CarbonImmutable $on, ?User $actor, string $partyId, DocumentType $type): int
    {
        $this->check($company, $partyId, $type, $requested);

        $total = 0;
        foreach ($requested as $item) {
            /** @var Document $document */
            $document = $this->books->query(Document::class, $company)->whereKey($item['document_id'])->lockForUpdate()->firstOrFail();
            $amount = (int) $item['amount_minor'];
            if ($amount > $document->balance() || ! $document->status->isPosted()) {
                throw ValidationException::withMessages(['allocations' => __('accounting::accounting.validation.allocation_amount')]);
            }

            $allocation = new Allocation;
            $allocation->fill([
                'organization_id' => $company->getKey(),
                'settlement_id' => $source['settlement_id'] ?? null,
                'credit_document_id' => $source['credit_document_id'] ?? null,
                'document_id' => $document->getKey(),
                'amount_minor' => $amount,
                'allocated_on' => $on->toDateString(),
                'created_by' => $actor?->getKey(),
            ])->save();

            $this->settle($document, $document->allocated_minor + $amount);
            $total += $amount;
        }

        return $total;
    }

    /**
     * Release every allocation of a voided source: the documents it paid owe
     * that amount again.
     *
     * @param  array{settlement_id?: string, credit_document_id?: string}  $source
     */
    public function release(Organization $company, array $source): void
    {
        $column = isset($source['settlement_id']) ? 'settlement_id' : 'credit_document_id';
        $allocations = $this->books->query(Allocation::class, $company)->where($column, $source[$column])->whereNull('voided_at')->get();

        foreach ($allocations as $allocation) {
            /** @var Document $document */
            $document = $this->books->query(Document::class, $company)->whereKey($allocation->document_id)->lockForUpdate()->firstOrFail();
            $this->settle($document, $document->allocated_minor - $allocation->amount_minor);
            $allocation->voided_at = now();
            $allocation->save();
        }
    }

    /** A document's settled amount and the status that follows from it. */
    public function settle(Document $document, int $allocated): void
    {
        $document->forceFill([
            'allocated_minor' => $allocated,
            'status' => DocumentStatus::settled($document->total_minor, $allocated),
            'version' => $document->version + 1,
        ])->save();
    }
}
