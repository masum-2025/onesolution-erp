<?php

namespace Modules\Pharmacy\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Services\Stock;
use Modules\Pharmacy\Events\ControlledDispensed;
use Modules\Pharmacy\Events\MedicinesSold;
use Modules\Pharmacy\Exceptions\PharmacyException;
use Modules\Pharmacy\Models\ControlledEntry;
use Modules\Pharmacy\Models\Medicine;
use Modules\Pharmacy\Models\SaleLink;
use Modules\Pos\Models\Register;
use Modules\Pos\Models\Sale;
use Modules\Pos\Services\Sales;
use Modules\Pos\Services\Tills;

/**
 * Selling at the pharmacy counter. Medicines go by piece, strip or box and
 * become pieces; each line is split over the good batches expiring first
 * (PharmacySaleGuard); the sale itself is a POS sale (its shift, payments,
 * receipt number and books), with the prescription passed to the guard.
 * The pharmacy then keeps its own record of the sale and writes every
 * controlled medicine that left into the register, batch by batch.
 *
 * An op id makes the sale safe to send again: the same sale comes back.
 */
class PharmacySales
{
    public function __construct(
        private PharmacyStore $store,
        private PharmacyRules $rules,
        private PharmacySaleGuard $guard,
        private Medicines $medicines,
        private Sales $sales,
        private Tills $tills,
        private Stock $stock,
        private AuditLogger $audit,
        private PharmacyPrescriptions $prescriptions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by SaleRequest.
     * @return array{sale: Sale, link: SaleLink}
     */
    public function sell(Organization $company, Register $register, array $data, User $actor): array
    {
        $done = $this->store->query(SaleLink::class, $company)->where('op_id', $data['op_id'])->first();
        if ($done !== null) {
            return ['sale' => $this->tills->query(Sale::class, $company)->whereKey($done->pos_sale_id)->firstOrFail(), 'link' => $done];
        }
        $unit = Organization::query()->findOrFail($register->unit_id);
        $rows = $this->medicines->details($company, array_values(array_unique(array_column($data['lines'], 'medicine_id'))), activeOnly: true);
        $loose = $this->rules->flag('sell_loose_pieces', $unit);

        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $row = $rows[$line['medicine_id']] ?? throw ValidationException::withMessages(["lines.{$index}.medicine_id" => __('pharmacy::messages.validation.medicine')]);
            /** @var Medicine $medicine */
            $medicine = $row['medicine'];
            $name = (string) ($row['item']['sku'] ?? $medicine->getKey());
            $pieces = Medicines::piecesIn($medicine, $line['pack']) ?? throw PharmacyException::noPack($name);
            if ($line['pack'] === 'piece' && ! $loose && $medicine->pieces_per_strip !== null) {
                throw PharmacyException::loosePieces($name);
            }
            $lines[] = ['item_id' => $medicine->item_id, 'quantity_milli' => $pieces * (int) $line['quantity'] * 1000, 'discount_minor' => (int) ($line['discount_minor'] ?? 0)];
        }

        $prescription = array_filter(array_map(fn ($value) => is_string($value) ? trim($value) : $value, (array) ($data['prescription'] ?? [])), fn ($value) => $value !== null && $value !== '');
        if (isset($prescription['consultation_id'])) {
            // From a doctor's prescription: the patient and doctor as written there, not as typed at the counter.
            $prescription = [...$prescription, ...array_filter($this->prescriptions->forSale($company, $unit, $prescription['consultation_id']), fn ($value) => $value !== null && $value !== '')];
        }
        $byItem = $rows->mapWithKeys(fn (array $row) => [$row['medicine']->item_id => $row])->all();
        if ($this->rules->flag('block_expired_sale', $unit)) {
            $lines = $this->guard->allocate($company, $register->warehouse_id, $lines, array_keys($byItem), $this->rules->today($this->store->timezone($company))->toDateString(),
                fn (PharmacyException $problem) => throw $problem, fn (string $itemId) => (string) ($byItem[$itemId]['item']['sku'] ?? $itemId));
        }

        $sale = $this->sales->sell($company, $register, [
            'op_id' => $data['op_id'], 'session_id' => $data['session_id'] ?? null, 'lines' => $lines, 'payments' => $data['payments'],
            'customer_name' => $data['customer_name'] ?? ($prescription['patient_name'] ?? null), 'customer_phone' => $data['customer_phone'] ?? null,
            'guard' => ['prescription' => $prescription],
        ], $actor);

        $controlled = $this->guard->controlledItems($company, array_map(fn (array $row) => $row['medicine'], $byItem));
        $entries = [];
        $link = $this->store->transaction($company, function () use ($company, $register, $data, $actor, $sale, $prescription, $lines, $byItem, $controlled, &$entries) {
            $link = new SaleLink([
                'organization_id' => $company->getKey(), 'unit_id' => $register->unit_id, 'pos_sale_id' => $sale->getKey(), 'sale_number' => $sale->number,
                'total_minor' => (int) $sale->total_minor, 'currency' => $sale->currency_code, 'sold_at' => $sale->sold_at,
                'patient_name' => $prescription['patient_name'] ?? null, 'prescription_ref' => $prescription['ref'] ?? null,
                'prescriber_name' => $prescription['prescriber_name'] ?? null, 'prescriber_bmdc' => $prescription['prescriber_bmdc'] ?? null,
                'consultation_id' => $prescription['consultation_id'] ?? null,
                'has_controlled' => $controlled !== [], 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'],
            ]);
            $link->save();

            if ($controlled !== []) {
                $balances = $this->stock->onHandAll($company, $controlled);
                $left = [];
                // Balance after each line: what is on hand now, plus what later lines of this sale took.
                foreach (array_reverse($lines) as $line) {
                    if (! in_array($line['item_id'], $controlled, true)) {
                        continue;
                    }
                    $left[$line['item_id']] ??= (int) ($balances[$line['item_id']] ?? 0);
                    $entries[] = [$line, $left[$line['item_id']]];
                    $left[$line['item_id']] += (int) $line['quantity_milli'];
                }
                $entries = array_reverse($entries);
                foreach ($entries as $index => [$line, $after]) {
                    $entry = new ControlledEntry([
                        'organization_id' => $company->getKey(), 'unit_id' => $register->unit_id, 'medicine_id' => $byItem[$line['item_id']]['medicine']->getKey(),
                        'batch_number' => $line['batch_number'] ?? null, 'direction' => 'out', 'quantity_milli' => (int) $line['quantity_milli'], 'balance_after_milli' => $after,
                        'source_type' => 'sale', 'source_id' => $link->getKey(), 'patient_name' => $prescription['patient_name'] ?? null,
                        'prescriber_bmdc' => $prescription['prescriber_bmdc'] ?? null, 'note' => $sale->number, 'recorded_by' => $actor->getKey(), 'recorded_at' => $sale->sold_at,
                    ]);
                    $entry->save();
                    $entries[$index] = $entry;
                }
            }
            $this->audit->record('pharmacy.sale_made', $link, new: ['sale_number' => $sale->number, 'total_minor' => (int) $sale->total_minor, 'has_controlled' => $controlled !== []],
                actor: $actor, organizationId: $company->getKey());

            return $link;
        });

        MedicinesSold::dispatch($company->getKey(), $link->getKey(), $sale->getKey(), (int) $sale->total_minor, $sale->currency_code, $link->has_controlled, $sale->sold_at->toDateString());
        foreach ($entries as $entry) {
            ControlledDispensed::dispatch($company->getKey(), $entry->getKey(), $entry->medicine_id, $entry->quantity_milli);
        }

        return ['sale' => $sale, 'link' => $link];
    }
}
