<?php

namespace Modules\Pharmacy\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Services\Documents;
use Modules\Inventory\Services\Stock;
use Modules\Pharmacy\Exceptions\PharmacyException;
use Modules\Pharmacy\Models\ControlledEntry;
use Modules\Pharmacy\Models\SupplierReturn;
use Modules\Pharmacy\Models\SupplierReturnLine;

/**
 * Expiring (or expired) stock going back to its supplier. Submitted by one
 * person, approved or rejected by another with pharmacy.approve (maker-checker);
 * approved, it becomes an Inventory issue of the named batches out of the
 * warehouse, and controlled medicines leave the register. Numbered with rule
 * pharmacy.number_prefixes ("SR-2026-000001").
 */
class SupplierReturns
{
    public function __construct(
        private PharmacyStore $store,
        private PharmacyRules $rules,
        private Medicines $medicines,
        private PharmacySaleGuard $guard,
        private Documents $documents,
        private Stock $stock,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by SupplierReturnRequest.
     */
    public function submit(Organization $company, Organization $unit, array $data, User $actor): SupplierReturn
    {
        $done = $this->store->query(SupplierReturn::class, $company)->where('op_id', $data['op_id'])->first();
        if ($done !== null) {
            return $done;
        }
        $warehouse = $this->stock->warehouse($company, $data['warehouse_id']);
        if ($warehouse === null || ! in_array($warehouse['unit_id'], $this->store->subtreeIds($unit), true)) {
            throw ValidationException::withMessages(['warehouse_id' => __('pharmacy::messages.validation.warehouse')]);
        }
        $rows = $this->medicines->details($company, array_values(array_unique(array_column($data['lines'], 'medicine_id'))));
        $held = collect($this->stock->batchesOnHand($company, $data['warehouse_id'], $rows->map(fn (array $row) => $row['medicine']->item_id)->values()->all()));
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $row = $rows[$line['medicine_id']] ?? throw ValidationException::withMessages(["lines.{$index}.medicine_id" => __('pharmacy::messages.validation.medicine')]);
            $batch = $held->first(fn (array $batch) => $batch['item_id'] === $row['medicine']->item_id && $batch['number'] === trim((string) $line['batch_number']));
            $quantity = (int) $line['quantity'] * 1000;
            if ($batch === null || $batch['quantity_milli'] < $quantity) {
                throw PharmacyException::batchShort((string) ($row['item']['sku'] ?? ''), (string) $line['batch_number']);
            }
            $lines[] = ['medicine_id' => $row['medicine']->getKey(), 'batch_number' => $batch['number'], 'expires_on' => $batch['expires_on'], 'quantity_milli' => $quantity];
        }

        return $this->store->transaction($company, function () use ($company, $unit, $data, $actor, $lines) {
            $return = new SupplierReturn([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'warehouse_id' => $data['warehouse_id'], 'supplier' => $data['supplier'],
                'reason' => $data['reason'], 'status' => SupplierReturn::SUBMITTED, 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'],
            ]);
            $return->save();
            foreach ($lines as $line) {
                (new SupplierReturnLine(['organization_id' => $company->getKey(), 'return_id' => $return->getKey(), ...$line]))->save();
            }
            $this->audit->record('pharmacy.return_submitted', $return, new: ['supplier' => $return->supplier, 'lines' => count($lines)], actor: $actor, organizationId: $company->getKey());

            return $return;
        });
    }

    public function decide(Organization $company, SupplierReturn $return, int $baseVersion, bool $approve, ?string $note, User $actor): SupplierReturn
    {
        return $this->store->transaction($company, function () use ($company, $return, $baseVersion, $approve, $note, $actor) {
            $return = $this->store->query(SupplierReturn::class, $company)->whereKey($return->getKey())->lockForUpdate()->firstOrFail();
            if ($return->version !== $baseVersion) {
                throw PharmacyException::versionConflict(['version' => $return->version]);
            }
            if ($return->status !== SupplierReturn::SUBMITTED) {
                throw PharmacyException::decided();
            }
            if ($return->created_by === $actor->getKey()) {
                throw PharmacyException::ownApproval();
            }
            $return->fill(['status' => $approve ? SupplierReturn::APPROVED : SupplierReturn::REJECTED, 'decided_by' => $actor->getKey(), 'decided_at' => now()]);
            $return->version++;

            if ($approve) {
                $lines = $this->store->query(SupplierReturnLine::class, $company)->where('return_id', $return->getKey())->get();
                $rows = $this->medicines->details($company, $lines->pluck('medicine_id')->unique()->values()->all());
                $document = $this->documents->create($company, [
                    'type' => 'issue', 'warehouse_id' => $return->warehouse_id, 'document_date' => CarbonImmutable::now($this->store->timezone($company))->toDateString(),
                    'counterparty' => $return->supplier, 'reason' => mb_substr($return->reason, 0, 300), 'op_id' => "pharmacy-return-{$return->getKey()}",
                    'lines' => $lines->map(fn (SupplierReturnLine $line) => ['item_id' => $rows[$line->medicine_id]['medicine']->item_id, 'quantity_milli' => $line->quantity_milli, 'batch_number' => $line->batch_number])->values()->all(),
                ], $actor);
                $this->documents->post($company, $document, (int) $document->version, $actor);
                $return->document_id = $document->getKey();
                $return->number = $this->number($company, $return);
                $this->registerOut($company, $return, $lines, $rows, $actor);
            }
            $return->save();
            $this->audit->record($approve ? 'pharmacy.return_approved' : 'pharmacy.return_rejected', $return, new: ['number' => $return->number, 'note' => $note], actor: $actor, organizationId: $company->getKey());

            return $return;
        });
    }

    private function registerOut(Organization $company, SupplierReturn $return, $lines, $rows, User $actor): void
    {
        $byItem = $rows->mapWithKeys(fn (array $row) => [$row['medicine']->item_id => $row['medicine']])->all();
        $controlled = $this->guard->controlledItems($company, $byItem);
        if ($controlled === []) {
            return;
        }
        $balance = $this->stock->onHandAll($company, $controlled);
        foreach ($lines as $line) {
            $itemId = $rows[$line->medicine_id]['medicine']->item_id;
            if (! in_array($itemId, $controlled, true)) {
                continue;
            }
            (new ControlledEntry([
                'organization_id' => $company->getKey(), 'unit_id' => $return->unit_id, 'medicine_id' => $line->medicine_id, 'batch_number' => $line->batch_number,
                'direction' => 'out', 'quantity_milli' => $line->quantity_milli, 'balance_after_milli' => (int) ($balance[$itemId] ?? 0), 'source_type' => 'supplier_return',
                'source_id' => $return->getKey(), 'note' => mb_substr($return->supplier, 0, 255), 'recorded_by' => $actor->getKey(), 'recorded_at' => now(),
            ]))->save();
        }
    }

    private function number(Organization $company, SupplierReturn $return): string
    {
        $prefix = (string) (((array) $this->rules->get('number_prefixes', Organization::query()->findOrFail($return->unit_id)))['return'] ?? 'SR');
        $year = CarbonImmutable::now($this->store->timezone($company))->year;
        $count = $this->store->query(SupplierReturn::class, $company)->whereNotNull('number')->where('number', 'like', "{$prefix}-{$year}-%")->lockForUpdate()->count();

        return sprintf('%s-%d-%06d', $prefix, $year, $count + 1);
    }
}
