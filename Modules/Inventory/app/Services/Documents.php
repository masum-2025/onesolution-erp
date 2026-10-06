<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\Bills;
use Modules\Inventory\Events\StockMoved;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\Document;
use Modules\Inventory\Models\DocumentLine;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Move;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;

/**
 * Receipts, issues, transfers and adjustments.
 *
 * - Receipt: stock in at the cost given; posted to stock against goods
 *   received not billed (inventory.grni), which the supplier's bill clears.
 * - Issue: stock out at its cost; posted to cost of goods (inventory.cogs).
 * - Adjustment (a reason; quantities up or down): posted at once, or, when
 *   its value is above rule inventory.adjustment_approval_above (empty =
 *   never), approved
 *   first by someone else (inventory.approve); against inventory.adjustment.
 * - Transfer: dispatched out of one warehouse (in transit), received in the
 *   other at the same cost; what did not arrive is a loss (inventory.adjustment).
 *
 * Every step is audited; an op id makes creating a document safe to retry.
 */
class Documents
{
    public function __construct(
        private Inventories $inventories,
        private StockLedger $ledger,
        private Numbers $numbers,
        private InventoryPostings $postings,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by DocumentRequest.
     */
    public function create(Organization $company, array $data, User $actor): Document
    {
        if (! empty($data['op_id'])) {
            $existing = $this->inventories->query(Document::class, $company)->where('op_id', $data['op_id'])->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        [$warehouse, $to] = $this->warehouses($company, $data['type'], $data['warehouse_id'], $data['to_warehouse_id'] ?? null);
        $lines = $this->checkLines($company, $data['type'], $data['lines']);

        return $this->inventories->transaction($company, function () use ($company, $data, $warehouse, $to, $lines, $actor) {
            $document = new Document;
            $document->fill([
                'organization_id' => $company->getKey(), 'type' => $data['type'], 'status' => Document::DRAFT, 'warehouse_id' => $warehouse->getKey(),
                'to_warehouse_id' => $to?->getKey(), 'document_date' => $data['document_date'], 'counterparty' => $data['counterparty'] ?? null,
                'reference' => $data['reference'] ?? null, 'reason' => $data['reason'] ?? null, 'currency_code' => $this->inventories->currency($company),
                'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null, 'version' => 1,
            ])->save();
            $this->writeLines($company, $document, $lines);
            $this->audit->record('inventory.document_created', $document, new: $this->values($document), actor: $actor, organizationId: $company->getKey());

            return $document;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $company, Document $document, int $baseVersion, array $data, User $actor): Document
    {
        [$warehouse, $to] = $this->warehouses($company, $document->type, $data['warehouse_id'] ?? $document->warehouse_id, $data['to_warehouse_id'] ?? $document->to_warehouse_id);
        $lines = isset($data['lines']) ? $this->checkLines($company, $document->type, $data['lines']) : null;

        return $this->inventories->transaction($company, function () use ($company, $document, $baseVersion, $data, $warehouse, $to, $lines, $actor) {
            $document = $this->locked($company, $document, $baseVersion, [Document::DRAFT]);
            $document->fill([
                'warehouse_id' => $warehouse->getKey(), 'to_warehouse_id' => $to?->getKey(),
                ...array_intersect_key($data, array_flip(['document_date', 'counterparty', 'reference', 'reason'])),
            ]);
            $document->version++;
            $document->save();
            if ($lines !== null) {
                $this->inventories->query(DocumentLine::class, $company)->where('document_id', $document->getKey())->delete();
                $this->writeLines($company, $document, $lines);
            }
            $this->audit->record('inventory.document_updated', $document, new: $this->values($document), actor: $actor, organizationId: $company->getKey());

            return $document;
        });
    }

    public function delete(Organization $company, Document $document, int $baseVersion, User $actor): void
    {
        $this->inventories->transaction($company, function () use ($company, $document, $baseVersion, $actor) {
            $document = $this->locked($company, $document, $baseVersion, [Document::DRAFT]);
            $this->inventories->query(DocumentLine::class, $company)->where('document_id', $document->getKey())->delete();
            $this->audit->record('inventory.document_deleted', $document, old: $this->values($document), actor: $actor, organizationId: $company->getKey());
            $document->delete();
        });
    }

    /** Post a receipt, issue or adjustment; an adjustment above the limit waits for someone else. */
    public function post(Organization $company, Document $document, int $baseVersion, User $actor): Document
    {
        return $this->inventories->transaction($company, function () use ($company, $document, $baseVersion, $actor) {
            $document = $this->locked($company, $document, $baseVersion, [Document::DRAFT]);
            if ($document->type === 'transfer') {
                throw InventoryException::wrongStatus($document->status);
            }
            if ($document->type === 'adjustment' && $this->needsApproval($company, $document)) {
                $document->forceFill(['status' => Document::PENDING, 'submitted_by' => $actor->getKey(), 'version' => $document->version + 1])->save();
                $this->audit->record('inventory.document_submitted', $document, new: $this->values($document), actor: $actor, organizationId: $company->getKey());

                return $document;
            }

            return $this->apply($company, $document, $actor);
        });
    }

    public function approve(Organization $company, Document $document, int $baseVersion, User $actor): Document
    {
        return $this->inventories->transaction($company, function () use ($company, $document, $baseVersion, $actor) {
            $document = $this->locked($company, $document, $baseVersion, [Document::PENDING]);
            if (in_array($actor->getKey(), [$document->created_by, $document->submitted_by], true)) {
                throw InventoryException::ownDocument();
            }
            $document->approved_by = $actor->getKey();

            return $this->apply($company, $document, $actor);
        });
    }

    public function reject(Organization $company, Document $document, int $baseVersion, string $reason, User $actor): Document
    {
        return $this->inventories->transaction($company, function () use ($company, $document, $baseVersion, $reason, $actor) {
            $document = $this->locked($company, $document, $baseVersion, [Document::PENDING]);
            if (in_array($actor->getKey(), [$document->created_by, $document->submitted_by], true)) {
                throw InventoryException::ownDocument();
            }
            $document->forceFill(['status' => Document::DRAFT, 'reject_reason' => $reason, 'submitted_by' => null, 'version' => $document->version + 1])->save();
            $this->audit->record('inventory.document_rejected', $document, new: $this->values($document), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $document;
        });
    }

    public function cancel(Organization $company, Document $document, int $baseVersion, User $actor): Document
    {
        return $this->inventories->transaction($company, function () use ($company, $document, $baseVersion, $actor) {
            $document = $this->locked($company, $document, $baseVersion, [Document::DRAFT, Document::PENDING]);
            $document->forceFill(['status' => Document::CANCELLED, 'version' => $document->version + 1])->save();
            $this->audit->record('inventory.document_cancelled', $document, new: $this->values($document), actor: $actor, organizationId: $company->getKey());

            return $document;
        });
    }

    /** A transfer leaves its warehouse (in transit, at its cost). */
    public function dispatch(Organization $company, Document $document, int $baseVersion, User $actor): Document
    {
        return $this->inventories->transaction($company, function () use ($company, $document, $baseVersion, $actor) {
            $document = $this->locked($company, $document, $baseVersion, [Document::DRAFT]);
            if ($document->type !== 'transfer') {
                throw InventoryException::wrongStatus($document->status);
            }
            $warehouse = $this->inventories->query(Warehouse::class, $company)->findOrFail($document->warehouse_id);
            $total = 0;
            foreach ($this->linesOf($company, $document) as $line) {
                $item = $this->item($company, $line->item_id);
                $moves = $this->ledger->move($company, $item, $warehouse, 'transfer_out', -$line->quantity_milli, null,
                    $this->source('transfer_line', $line->getKey(), $actor), $document->document_date, ['number' => $line->batch_number]);
                $value = -array_sum(array_map(fn (Move $move) => $move->value_minor, $moves));
                $line->forceFill(['value_minor' => $value])->save();
                $total += $value;
            }
            $number = $document->number ?? $this->numbers->next($company, 'transfer', $document->document_date);
            $document->forceFill(['status' => Document::IN_TRANSIT, 'number' => $number, 'value_minor' => $total, 'posted_at' => now(), 'posted_by' => $actor->getKey(), 'version' => $document->version + 1])->save();
            $this->audit->record('inventory.document_dispatched', $document, new: $this->values($document), actor: $actor, organizationId: $company->getKey());

            return $document;
        });
    }

    /**
     * A transfer arrives; what did not arrive (received below sent) is a loss.
     *
     * @param  array<string, int>  $received  Line id => quantity received (missing = all of it).
     */
    public function receive(Organization $company, Document $document, int $baseVersion, array $received, User $actor): Document
    {
        return $this->inventories->transaction($company, function () use ($company, $document, $baseVersion, $received, $actor) {
            $document = $this->locked($company, $document, $baseVersion, [Document::IN_TRANSIT]);
            $to = $this->inventories->query(Warehouse::class, $company)->findOrFail($document->to_warehouse_id);
            $lost = 0;
            foreach ($this->linesOf($company, $document) as $line) {
                $item = $this->item($company, $line->item_id);
                $arrived = $received[$line->getKey()] ?? $line->quantity_milli;
                if ($arrived < 0 || $arrived > $line->quantity_milli) {
                    throw ValidationException::withMessages(["received.{$line->getKey()}" => __('inventory::inventory.validation.received_range')]);
                }
                // Each batch that left comes in again, in order, until what arrived is used up.
                $left = $arrived;
                $value = 0;
                foreach ($this->inventories->query(Move::class, $company)->where('source_type', 'transfer_line')->where('source_id', $line->getKey())->orderBy('id')->get() as $out) {
                    $sent = -$out->quantity_milli;
                    $take = min($left, $sent);
                    if ($take <= 0) {
                        break;
                    }
                    $outValue = -$out->value_minor;
                    $unitCost = $sent > 0 ? intdiv($outValue * 1000 + intdiv($sent, 2), $sent) : 0;
                    $exact = $take === $sent ? $outValue : intdiv($outValue * $take + intdiv($sent, 2), $sent);
                    $batch = $out->batch_id === null ? [] : ['number' => $this->inventories->query(Batch::class, $company)->whereKey($out->batch_id)->value('number')];
                    $in = $this->ledger->move($company, $item, $to, 'transfer_in', $take, $unitCost, $this->source('transfer_line', $line->getKey(), $actor), $document->document_date, $batch, $exact);
                    $value += $in[0]->value_minor;
                    $left -= $take;
                }
                $line->forceFill(['received_milli' => $arrived])->save();
                $lost += $line->value_minor - $value;
            }
            $journal = $lost === 0 ? null : $this->postings->post($company, "inventory-transfer-loss-{$document->getKey()}", $document->document_date,
                __('inventory::inventory.narration.transfer_loss', ['number' => $document->number]), 'transfer', $document->getKey(), $document->currency_code,
                InventoryPostings::against('inventory.adjustment', -$lost));
            $document->forceFill(['status' => Document::POSTED, 'received_at' => now(), 'received_by' => $actor->getKey(), 'receipt_journal_id' => $journal, 'version' => $document->version + 1])->save();
            $this->audit->record('inventory.document_received', $document, new: [...$this->values($document), 'lost_minor' => $lost], actor: $actor, organizationId: $company->getKey());
            StockMoved::dispatch($company->getKey(), 'document', $document->getKey());

            return $document;
        });
    }

    /** @return Collection<int, DocumentLine> */
    public function linesOf(Organization $company, Document $document): Collection
    {
        return $this->inventories->query(DocumentLine::class, $company)->where('document_id', $document->getKey())->orderBy('line_no')->get();
    }

    /** Moves, value, number and the posting of a receipt, issue or adjustment. */
    private function apply(Organization $company, Document $document, User $actor): Document
    {
        $warehouse = $this->inventories->query(Warehouse::class, $company)->findOrFail($document->warehouse_id);
        $kind = ['receipt' => 'receipt', 'issue' => 'issue', 'adjustment' => 'adjustment'][$document->type];
        $total = 0;
        foreach ($this->linesOf($company, $document) as $line) {
            $item = $this->item($company, $line->item_id);
            $quantity = $document->type === 'issue' ? -$line->quantity_milli : $line->quantity_milli;
            $moves = $this->ledger->move($company, $item, $warehouse, $kind, $quantity, $line->unit_cost_minor, $this->source('document', $document->getKey(), $actor),
                $document->document_date, ['number' => $line->batch_number, 'expires_on' => $line->expires_on?->toDateString()]);
            $value = array_sum(array_map(fn (Move $move) => $move->value_minor, $moves));
            $line->forceFill(['value_minor' => $value])->save();
            $total += $value;
        }

        $other = ['receipt' => 'inventory.grni', 'issue' => 'inventory.cogs', 'adjustment' => 'inventory.adjustment'][$document->type];
        $number = $document->number ?? $this->numbers->next($company, $document->type, $document->document_date);
        $journal = $this->postings->post($company, "inventory-document-{$document->getKey()}", $document->document_date,
            __("inventory::inventory.narration.{$document->type}", ['number' => $number]), $document->type, $document->getKey(), $document->currency_code,
            InventoryPostings::against($other, $total, $warehouse->unit_id));

        $document->forceFill(['status' => Document::POSTED, 'number' => $number, 'value_minor' => $total, 'posted_at' => now(), 'posted_by' => $actor->getKey(),
            'journal_id' => $journal, 'version' => $document->version + 1])->save();
        $this->audit->record('inventory.document_posted', $document, new: [...$this->values($document), 'journal_id' => $journal], actor: $actor, organizationId: $company->getKey());
        StockMoved::dispatch($company->getKey(), 'document', $document->getKey());

        return $document;
    }

    /** An adjustment's value at today's cost (given costs for lines up) above the rule's limit. */
    private function needsApproval(Organization $company, Document $document): bool
    {
        $limit = $this->rules->get('inventory.adjustment_approval_above', $this->contexts->forOrganization($company));
        if (! is_array($limit)) {
            return false;
        }
        $warehouse = $this->inventories->query(Warehouse::class, $company)->findOrFail($document->warehouse_id);
        $value = 0;
        foreach ($this->linesOf($company, $document) as $line) {
            $cost = $line->unit_cost_minor ?? $this->ledger->unitCost($company, $this->item($company, $line->item_id), $warehouse);
            $value += abs(StockLedger::value($line->quantity_milli, $cost));
        }

        // An amount in another currency than the rule's cannot be compared: it always waits.
        return $limit['currency'] !== $document->currency_code || $value > (int) $limit['amount'];
    }

    /**
     * The warehouses: active, the company's, and two different ones for a transfer.
     *
     * @return array{0: Warehouse, 1: Warehouse|null}
     */
    private function warehouses(Organization $company, string $type, string $from, ?string $to): array
    {
        $find = fn (string $id) => $this->inventories->query(Warehouse::class, $company)->whereKey($id)->first() ?? throw InventoryException::notFound('warehouse');
        $warehouse = $find($from);
        if (! $warehouse->is_active) {
            throw InventoryException::inactive('warehouse');
        }
        if ($type !== 'transfer') {
            return [$warehouse, null];
        }
        if ($to === null) {
            throw ValidationException::withMessages(['to_warehouse_id' => __('inventory::inventory.validation.to_warehouse')]);
        }
        $target = $find($to);
        if ($target->getKey() === $warehouse->getKey()) {
            throw InventoryException::sameWarehouse();
        }
        if (! $target->is_active) {
            throw InventoryException::inactive('warehouse');
        }

        return [$warehouse, $target];
    }

    /**
     * Lines checked against the items: the company's, active, kept in stock;
     * quantities with no more decimals than the unit allows; a cost for
     * receipts; a batch where the item tracks batches and stock comes in.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function checkLines(Organization $company, string $type, array $lines): array
    {
        $items = $this->inventories->query(Item::class, $company)->whereIn('id', array_column($lines, 'item_id'))->get()->keyBy('id');
        $units = $this->inventories->query(Unit::class, $company)->whereIn('id', $items->pluck('unit_id')->all())->get()->keyBy('id');
        $errors = [];
        foreach ($lines as $index => $line) {
            $item = $items[$line['item_id']] ?? null;
            $quantity = (int) $line['quantity_milli'];
            if ($item === null || ! $item->is_active) {
                $errors["lines.{$index}.item_id"] = __('inventory::inventory.validation.item');

                continue;
            }
            if (! $item->keepsStock()) {
                $errors["lines.{$index}.item_id"] = __('inventory::inventory.errors.not_stock_item', ['item' => $item->sku]);

                continue;
            }
            if ($quantity === 0 || ($type !== 'adjustment' && $quantity < 0)) {
                $errors["lines.{$index}.quantity_milli"] = __('inventory::inventory.validation.quantity');
            }
            $step = 10 ** (3 - min(3, (int) ($units[$item->unit_id]->decimals ?? 0)));
            if ($quantity % $step !== 0) {
                $errors["lines.{$index}.quantity_milli"] = __('inventory::inventory.validation.decimals', ['decimals' => (int) ($units[$item->unit_id]->decimals ?? 0)]);
            }
            if ($type === 'receipt' && ! isset($line['unit_cost_minor'])) {
                $errors["lines.{$index}.unit_cost_minor"] = __('inventory::inventory.validation.unit_cost');
            }
            $comingIn = $type === 'receipt' || ($type === 'adjustment' && $quantity > 0);
            if ($item->track_batches && $comingIn && trim((string) ($line['batch_number'] ?? '')) === '') {
                $errors["lines.{$index}.batch_number"] = __('inventory::inventory.errors.batch_needed', ['item' => $item->sku]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_values($lines);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(Organization $company, Document $document, array $lines): void
    {
        foreach ($lines as $index => $line) {
            (new DocumentLine)->fill([
                'organization_id' => $company->getKey(), 'document_id' => $document->getKey(), 'line_no' => $index + 1, 'item_id' => $line['item_id'],
                'quantity_milli' => (int) $line['quantity_milli'], 'unit_cost_minor' => $line['unit_cost_minor'] ?? null,
                'batch_number' => isset($line['batch_number']) ? trim((string) $line['batch_number']) ?: null : null,
                'expires_on' => $line['expires_on'] ?? null, 'note' => $line['note'] ?? null,
            ])->save();
        }
    }

    private function item(Organization $company, string $id): Item
    {
        return $this->inventories->query(Item::class, $company)->whereKey($id)->first() ?? throw InventoryException::notFound('item');
    }

    /**
     * @param  list<string>  $statuses
     */
    private function locked(Organization $company, Document $document, ?int $baseVersion, array $statuses): Document
    {
        /** @var Document $fresh */
        $fresh = $this->inventories->query(Document::class, $company)->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw InventoryException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }
        if (! in_array($fresh->status, $statuses, true)) {
            throw InventoryException::wrongStatus($fresh->status);
        }

        return $fresh;
    }

    /**
     * The supplier's bill for a posted goods receipt: a draft bill in
     * Accounting (its public Bills service) at the receipt's costs, against
     * goods received not billed, so the bill clears what the receipt parked
     * there. Once per receipt; the person finishes the bill in Accounting.
     */
    public function bill(Organization $company, Document $document, int $baseVersion, string $partyId, ?string $issueDate, User $actor): Document
    {
        return $this->inventories->transaction($company, function () use ($company, $document, $baseVersion, $partyId, $issueDate, $actor) {
            $document = $this->locked($company, $document, $baseVersion, [Document::POSTED]);
            if ($document->type !== 'receipt' || $document->bill_id !== null) {
                throw InventoryException::notBillable();
            }
            $warehouse = $this->inventories->query(Warehouse::class, $company)->findOrFail($document->warehouse_id);
            $items = $this->inventories->query(Item::class, $company)->whereKey($this->linesOf($company, $document)->pluck('item_id')->unique()->all())->get()->keyBy('id');
            $lines = $this->linesOf($company, $document)->map(fn (DocumentLine $line) => [
                'description' => trim($items[$line->item_id]->sku.' '.$items[$line->item_id]->name.($line->batch_number ? " ({$line->batch_number})" : '')),
                'quantity_milli' => $line->quantity_milli,
                'unit_price_minor' => (int) $line->unit_cost_minor,
            ])->values()->all();

            $bill = app(Bills::class)->draftFor($company, [
                'party_id' => $partyId,
                'issue_date' => $issueDate ?? $document->document_date->toDateString(),
                'reference' => $document->reference ?: $document->number,
                'notes' => __('inventory::inventory.narration.bill', ['number' => $document->number]),
                'clearing_key' => 'inventory.grni',
                'cost_centre_id' => $warehouse->unit_id,
                'lines' => $lines,
            ], $actor);

            $document->forceFill(['bill_id' => $bill['id'], 'billed_at' => now(), 'version' => $document->version + 1])->save();
            $this->audit->record('inventory.document_billed', $document, new: [...$this->values($document), 'bill_id' => $bill['id'], 'bill_total_minor' => $bill['total_minor']], actor: $actor, organizationId: $company->getKey());

            return $document;
        });
    }

    /**
     * @return array{source_module: string, source_type: string, source_id: string, actor_id: string}
     */
    private function source(string $type, string $id, User $actor): array
    {
        return ['source_module' => 'inventory', 'source_type' => $type, 'source_id' => $id, 'actor_id' => $actor->getKey()];
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Document $document): array
    {
        return [...$document->only(['type', 'number', 'status', 'warehouse_id', 'to_warehouse_id', 'value_minor', 'currency_code']), 'document_date' => $document->document_date->toDateString()];
    }
}
