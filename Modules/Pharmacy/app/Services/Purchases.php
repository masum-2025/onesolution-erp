<?php

namespace Modules\Pharmacy\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Services\Documents;
use Modules\Inventory\Services\Stock;
use Modules\Pharmacy\Models\ControlledEntry;
use Modules\Pharmacy\Models\Purchase;

/**
 * Buying medicines: an Inventory receipt into a warehouse of the unit, every
 * line with its batch number and expiry (a medicine without them is refused),
 * posted at once so the stock is there to sell. Controlled medicines that
 * came in are written into the register. An op id makes it safe to send again.
 */
class Purchases
{
    public function __construct(
        private PharmacyStore $store,
        private Medicines $medicines,
        private PharmacySaleGuard $guard,
        private Documents $documents,
        private Stock $stock,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by PurchaseRequest.
     */
    public function record(Organization $company, Organization $unit, array $data, User $actor): Purchase
    {
        $done = $this->store->query(Purchase::class, $company)->where('op_id', $data['op_id'])->first();
        if ($done !== null) {
            return $done;
        }
        $warehouse = $this->stock->warehouse($company, $data['warehouse_id']);
        if ($warehouse === null || ! $warehouse['is_active'] || ! in_array($warehouse['unit_id'], $this->store->subtreeIds($unit), true)) {
            throw ValidationException::withMessages(['warehouse_id' => __('pharmacy::messages.validation.warehouse')]);
        }
        $rows = $this->medicines->details($company, array_values(array_unique(array_column($data['lines'], 'medicine_id'))));
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $row = $rows[$line['medicine_id']] ?? throw ValidationException::withMessages(["lines.{$index}.medicine_id" => __('pharmacy::messages.validation.medicine')]);
            $pieces = Medicines::piecesIn($row['medicine'], $line['pack']) ?? throw ValidationException::withMessages(["lines.{$index}.pack" => __('pharmacy::messages.validation.pack')]);
            $lines[] = [
                'item_id' => $row['medicine']->item_id, 'quantity_milli' => $pieces * (int) $line['quantity'] * 1000,
                // The cost given is of one pack; Inventory keeps the cost of one piece.
                'unit_cost_minor' => intdiv((int) $line['pack_cost_minor'] + intdiv($pieces, 2), $pieces),
                'batch_number' => trim((string) $line['batch_number']), 'expires_on' => $line['expires_on'],
            ];
        }

        return $this->store->transaction($company, function () use ($company, $unit, $data, $actor, $lines, $rows) {
            $document = $this->documents->create($company, [
                'type' => 'receipt', 'warehouse_id' => $data['warehouse_id'], 'document_date' => $data['purchased_on'], 'counterparty' => $data['supplier'],
                'reference' => $data['reference'] ?? null, 'op_id' => "pharmacy-purchase-{$data['op_id']}", 'lines' => $lines,
            ], $actor);
            $document = $this->documents->post($company, $document, (int) $document->version, $actor);

            $purchase = new Purchase([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'document_id' => $document->getKey(), 'document_number' => $document->number,
                'warehouse_id' => $data['warehouse_id'], 'supplier' => $data['supplier'], 'reference' => $data['reference'] ?? null, 'purchased_on' => $data['purchased_on'],
                'total_minor' => (int) $document->value_minor,
                'currency' => $this->store->currency($company), 'line_count' => count($lines), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'],
            ]);
            $purchase->save();

            $byItem = $rows->mapWithKeys(fn (array $row) => [$row['medicine']->item_id => $row['medicine']])->all();
            $controlled = $this->guard->controlledItems($company, $byItem);
            if ($controlled !== []) {
                $balance = $this->stock->onHandAll($company, $controlled);
                $later = [];
                foreach (array_reverse($lines) as $line) {
                    if (! in_array($line['item_id'], $controlled, true)) {
                        continue;
                    }
                    $after = (int) ($balance[$line['item_id']] ?? 0) - ($later[$line['item_id']] ?? 0);
                    $later[$line['item_id']] = ($later[$line['item_id']] ?? 0) + $line['quantity_milli'];
                    (new ControlledEntry([
                        'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'medicine_id' => $byItem[$line['item_id']]->getKey(), 'batch_number' => $line['batch_number'],
                        'direction' => 'in', 'quantity_milli' => $line['quantity_milli'], 'balance_after_milli' => $after, 'source_type' => 'purchase', 'source_id' => $purchase->getKey(),
                        'note' => mb_substr($data['supplier'], 0, 255), 'recorded_by' => $actor->getKey(), 'recorded_at' => now(),
                    ]))->save();
                }
            }
            $this->audit->record('pharmacy.purchase_made', $purchase, new: ['document_number' => $document->number, 'supplier' => $data['supplier'], 'total_minor' => $purchase->total_minor],
                actor: $actor, organizationId: $company->getKey());

            return $purchase;
        });
    }
}
