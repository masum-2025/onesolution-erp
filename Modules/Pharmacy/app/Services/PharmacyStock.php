<?php

namespace Modules\Pharmacy\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\Inventory\Services\Stock;
use Modules\Pos\Models\Register;

/**
 * Medicine stock as the pharmacy reads it, from Inventory's batches in the
 * warehouses of a unit and below: each batch with its expiry and whether it is
 * expired, expiring soon (rule pharmacy.near_expiry_days) or due back to the
 * supplier (rule pharmacy.return_before_expiry_days); and what a counter can
 * sell (good batches only).
 */
class PharmacyStock
{
    public function __construct(
        private PharmacyStore $store,
        private PharmacyRules $rules,
        private Medicines $medicines,
        private Stock $stock,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function batches(Organization $company, Organization $unit, string $show = 'all', ?string $warehouseId = null): array
    {
        $warehouses = collect($this->stock->warehouses($company))->whereIn('unit_id', $this->store->subtreeIds($unit))->keyBy('id');
        if ($warehouseId !== null) {
            $warehouses = $warehouses->only([$warehouseId]);
        }
        $rows = $this->medicines->details($company);
        if ($rows->isEmpty() || $warehouses->isEmpty()) {
            return [];
        }
        $byItem = $rows->mapWithKeys(fn (array $row) => [$row['medicine']->item_id => $row]);
        $today = $this->rules->today($this->store->timezone($company));
        $near = $today->addDays($this->rules->days('near_expiry_days', $unit))->toDateString();
        $back = $today->addDays($this->rules->days('return_before_expiry_days', $unit))->toDateString();
        $todayText = $today->toDateString();

        $out = [];
        foreach ($this->stock->batchesOnHand($company, null, $byItem->keys()->all()) as $batch) {
            if (! $warehouses->has($batch['warehouse_id'])) {
                continue;
            }
            $expires = $batch['expires_on'];
            $state = match (true) {
                $expires !== null && $expires < $todayText => 'expired',
                $expires !== null && $expires <= $near => 'near',
                $expires !== null && $expires <= $back => 'return',
                default => 'ok',
            };
            if ($show !== 'all' && $show !== $state && ! ($show === 'return' && in_array($state, ['near', 'expired'], true))) {
                continue;
            }
            $row = $byItem[$batch['item_id']];
            $out[] = [
                'medicine_id' => $row['medicine']->getKey(), 'item_id' => $batch['item_id'], 'sku' => $row['item']['sku'] ?? null, 'name' => $row['item']['names'] ?? [],
                'generic' => $row['generic']?->texts('name') ?? [], 'strength' => $row['medicine']->strength, 'form' => $row['medicine']->form, 'is_controlled' => $row['is_controlled'],
                'warehouse_id' => $batch['warehouse_id'], 'warehouse' => $warehouses[$batch['warehouse_id']]['name'], 'batch_number' => $batch['number'], 'expires_on' => $expires,
                'days_left' => $expires === null ? null : (int) $today->diffInDays($expires, false), 'quantity_milli' => $batch['quantity_milli'], 'state' => $state,
                'mrp_minor' => $row['medicine']->mrp_minor, 'currency' => $row['medicine']->currency,
            ];
        }

        return $out;
    }

    /** @return array{near: int, expired: int} Batches (with stock) expiring soon and expired. */
    public function expiryCounts(Organization $company, Organization $unit): array
    {
        $states = array_count_values(array_column($this->batches($company, $unit), 'state'));

        return ['near' => $states['near'] ?? 0, 'expired' => $states['expired'] ?? 0];
    }

    /**
     * What a counter sells of medicines: each with its packs, MRP, what good
     * batches hold, the first expiry and whether it is soon. Prices and VAT
     * come from the POS catalogue.
     *
     * @return list<array<string, mixed>>
     */
    public function counter(Organization $company, Register $register): array
    {
        $unit = Organization::query()->findOrFail($register->unit_id);
        $rows = $this->medicines->details($company, null, activeOnly: true);
        if ($rows->isEmpty()) {
            return [];
        }
        $today = $this->rules->today($this->store->timezone($company))->toDateString();
        $near = $this->rules->today($this->store->timezone($company))->addDays($this->rules->days('near_expiry_days', $unit))->toDateString();
        $blockExpired = $this->rules->flag('block_expired_sale', $unit);
        $batches = collect($this->stock->batchesOnHand($company, $register->warehouse_id, $rows->map(fn (array $row) => $row['medicine']->item_id)->values()->all()))->groupBy('item_id');

        return $rows->map(function (array $row) use ($batches, $today, $near, $blockExpired) {
            $medicine = $row['medicine'];
            $held = $batches[$medicine->item_id] ?? collect();
            $good = $blockExpired ? $held->filter(fn (array $batch) => $batch['expires_on'] === null || $batch['expires_on'] >= $today) : $held;
            $first = $good->first();

            return [
                'medicine_id' => $medicine->getKey(), 'item_id' => $medicine->item_id, 'generic_id' => $medicine->generic_id, 'generic' => $row['generic']?->texts('name') ?? [],
                'strength' => $medicine->strength, 'form' => $medicine->form, 'mrp_minor' => $medicine->mrp_minor, 'pieces_per_strip' => $medicine->pieces_per_strip,
                'strips_per_box' => $medicine->strips_per_box, 'requires_prescription' => $medicine->requires_prescription, 'is_controlled' => $row['is_controlled'],
                'sellable_milli' => (int) $good->sum('quantity_milli'), 'expired_milli' => (int) $held->sum('quantity_milli') - (int) $good->sum('quantity_milli'),
                'first_expiry' => $first['expires_on'] ?? null, 'first_batch' => $first['number'] ?? null, 'near_expiry' => $first !== null && $first['expires_on'] !== null && $first['expires_on'] <= $near,
            ];
        })->values()->all();
    }
}
