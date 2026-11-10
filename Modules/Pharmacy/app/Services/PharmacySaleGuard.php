<?php

namespace Modules\Pharmacy\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Inventory\Services\Stock;
use Modules\Pharmacy\Exceptions\PharmacyException;
use Modules\Pharmacy\Models\Generic;
use Modules\Pharmacy\Models\Medicine;
use Modules\Pos\Contracts\SaleGuard;
use Modules\Pos\Models\Register;
use Modules\Pos\Services\Pricing;

/**
 * The pharmacy's say over every POS sale of a medicine, at the POS till or the
 * pharmacy counter, while the module is on at the counter's branch:
 *
 * - never above the MRP (rule pharmacy.block_above_mrp);
 * - a controlled medicine only with the prescription's details: the patient's
 *   name and the prescriber's BMDC no. (rule pharmacy.controlled_requires_prescription),
 *   which only the pharmacy counter can pass (the POS till cannot);
 * - never an expired batch (rule pharmacy.block_expired_sale): a line is split
 *   over the batches expiring first that are still good; when only expired
 *   stock is left the sale is refused.
 *
 * Online a broken rule refuses the sale; a sale already made offline is kept
 * and marked for a supervisor's review.
 */
class PharmacySaleGuard implements SaleGuard
{
    public function __construct(
        private PharmacyStore $store,
        private PharmacyRules $rules,
        private Stock $stock,
        private ModuleResolver $modules,
    ) {}

    public function prepare(Organization $company, Register $register, array $items, array $lines, array $context, bool $offline): array
    {
        $unit = Organization::query()->findOrFail($register->unit_id);
        if (! $this->modules->isEnabled('pharmacy', $unit)) {
            return ['lines' => $lines, 'reviews' => []];
        }
        $medicines = $this->store->query(Medicine::class, $company)->whereIn('item_id', array_values(array_unique(array_column($lines, 'item_id'))))->get()->keyBy('item_id');
        if ($medicines->isEmpty()) {
            return ['lines' => $lines, 'reviews' => []];
        }
        $reviews = [];
        $refuse = function (PharmacyException $problem) use ($offline, &$reviews): void {
            if (! $offline) {
                throw $problem;
            }
            $reviews[] = $problem->errorCode();
        };
        $sku = fn (string $itemId) => (string) ($items[$itemId]['sku'] ?? $itemId);

        if ($this->rules->flag('block_above_mrp', $unit)) {
            foreach ($medicines as $itemId => $medicine) {
                if ((int) ($items[$itemId]['sale_price_minor'] ?? 0) > $medicine->mrp_minor) {
                    $refuse(PharmacyException::aboveMrp($sku($itemId)));
                }
            }
        }

        $controlled = $this->controlledItems($company, $medicines->all());
        if ($controlled !== [] && $this->rules->flag('controlled_requires_prescription', $unit) && ! self::hasPrescription($context['prescription'] ?? null)) {
            $refuse(PharmacyException::prescriptionNeeded($sku($controlled[0])));
        }

        if ($this->rules->flag('block_expired_sale', $unit)) {
            $lines = $this->allocate($company, $register->warehouse_id, $lines, $medicines->keys()->all(), $this->rules->today($this->store->timezone($company))->toDateString(), $refuse, $sku);
        }

        return ['lines' => $lines, 'reviews' => array_values(array_unique($reviews))];
    }

    /**
     * Lines of medicines split over the good batches expiring first (lines of
     * other items as they are). A named batch must hold the quantity and be
     * good. What the batches cannot cover goes without a batch, unless only
     * expired stock is left.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $medicineItems
     * @param  callable(PharmacyException): void  $refuse
     * @param  callable(string): string  $sku
     * @return list<array<string, mixed>>
     */
    public function allocate(Organization $company, string $warehouseId, array $lines, array $medicineItems, string $today, callable $refuse, callable $sku): array
    {
        $held = collect($this->stock->batchesOnHand($company, $warehouseId, $medicineItems))->groupBy('item_id');
        // Taken by earlier lines of this sale, per batch.
        $used = [];
        $out = [];
        foreach ($lines as $line) {
            if (! in_array($line['item_id'], $medicineItems, true)) {
                $out[] = $line;

                continue;
            }
            $batches = $held[$line['item_id']] ?? collect();
            $named = trim((string) ($line['batch_number'] ?? ''));
            if ($named !== '') {
                $batch = $batches->firstWhere('number', $named);
                if ($batch !== null && $batch['expires_on'] !== null && $batch['expires_on'] < $today) {
                    $refuse(PharmacyException::expiredStock($sku($line['item_id'])));
                }
                $out[] = $line;

                continue;
            }

            $wanted = (int) $line['quantity_milli'];
            $parts = [];
            foreach ($batches as $batch) {
                if ($wanted <= 0) {
                    break;
                }
                if ($batch['expires_on'] !== null && $batch['expires_on'] < $today) {
                    continue;
                }
                $free = $batch['quantity_milli'] - ($used[$batch['batch_id']] ?? 0);
                if ($free <= 0) {
                    continue;
                }
                $take = min($wanted, $free);
                $used[$batch['batch_id']] = ($used[$batch['batch_id']] ?? 0) + $take;
                $parts[] = [$batch['number'], $take];
                $wanted -= $take;
            }
            if ($wanted > 0) {
                $expiredLeft = $batches->contains(fn (array $batch) => $batch['expires_on'] !== null && $batch['expires_on'] < $today);
                if ($expiredLeft) {
                    $refuse(PharmacyException::expiredStock($sku($line['item_id'])));
                }
                // Beyond the batches (Inventory decides: refused, or below zero where allowed).
                $parts[] = [null, $wanted];
            }

            $discount = (int) ($line['discount_minor'] ?? 0);
            $given = 0;
            foreach ($parts as $index => [$number, $quantity]) {
                $share = $index === array_key_last($parts) ? $discount - $given : Pricing::part($discount, $quantity, (int) $line['quantity_milli']);
                $given += $share;
                $out[] = [...$line, 'quantity_milli' => $quantity, 'discount_minor' => $share, 'batch_number' => $number];
            }
        }

        return $out;
    }

    /** Whether a prescription names the patient and the prescriber's BMDC no. */
    public static function hasPrescription(mixed $prescription): bool
    {
        return is_array($prescription)
            && trim((string) ($prescription['patient_name'] ?? '')) !== ''
            && trim((string) ($prescription['prescriber_bmdc'] ?? '')) !== '';
    }

    /**
     * Items of controlled medicines, in the order given.
     *
     * @param  array<string, Medicine>  $medicines  keyed by item id
     * @return list<string>
     */
    public function controlledItems(Organization $company, array $medicines): array
    {
        $controlled = $this->store->query(Generic::class, $company)->whereIn('id', array_values(array_unique(array_map(fn (Medicine $medicine) => $medicine->generic_id, $medicines))))
            ->where('is_controlled', true)->pluck('id')->map(fn ($id) => (string) $id)->all();

        return array_values(array_map('strval', array_keys(array_filter($medicines, fn (Medicine $medicine) => in_array($medicine->generic_id, $controlled, true)))));
    }
}
