<?php

namespace Modules\Pharmacy\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Services\Catalog;
use Modules\Inventory\Services\Stock;
use Modules\Pharmacy\Exceptions\PharmacyException;
use Modules\Pharmacy\Models\Generic;
use Modules\Pharmacy\Models\Manufacturer;
use Modules\Pharmacy\Models\Medicine;

/**
 * Medicines: an Inventory item (brand name, SKU, barcode, selling price of one
 * piece, VAT code, batches kept) plus what only a pharmacy needs (generic,
 * strength, form, DGDA no., MRP of one piece, pieces per strip, strips per box).
 *
 * The item is made and changed through Inventory's Catalog, so stock,
 * batches and the till work as for any item. A selling price above the MRP
 * is refused where rule pharmacy.block_above_mrp says so; an empty price sells
 * at the MRP. Quantities everywhere are pieces (in thousandths, like Inventory).
 */
class Medicines
{
    private const ITEM_FIELDS = ['sku', 'barcode', 'tax_code_id'];

    private const OWN_FIELDS = ['generic_id', 'manufacturer_id', 'strength', 'form', 'dgda_no', 'mrp_minor', 'pieces_per_strip', 'strips_per_box', 'requires_prescription', 'is_active'];

    public function __construct(
        private PharmacyStore $store,
        private PharmacyRules $rules,
        private Catalog $catalog,
        private Stock $stock,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by MedicineRequest.
     */
    public function save(Organization $company, Organization $unit, ?Medicine $record, array $data, User $actor): Medicine
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Medicine::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        $this->checkLinks($company, $data);
        $mrp = (int) ($data['mrp_minor'] ?? $record?->mrp_minor);
        $price = array_key_exists('sale_price_minor', $data) ? $data['sale_price_minor'] : ($record === null ? null : $this->item($company, $record)['sale_price_minor']);
        $price = $price === null ? $mrp : (int) $price;
        if ($price > $mrp && $this->rules->flag('block_above_mrp', $unit)) {
            throw ValidationException::withMessages(['sale_price_minor' => __('pharmacy::messages.validation.above_mrp')]);
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor, $price) {
            $item = array_intersect_key($data, array_flip(self::ITEM_FIELDS));
            if (isset($data['brand_name'])) {
                $item['name'] = $data['brand_name'];
            }
            $item['sale_price_minor'] = $price;
            if (array_key_exists('is_active', $data)) {
                $item['is_active'] = (bool) $data['is_active'];
            }

            if ($record === null) {
                $made = $this->catalog->create($company, 'item', [...$item, 'unit_id' => $this->piecesUnit($company, $actor), 'kind' => 'stock', 'track_batches' => true], $actor);
                $record = new Medicine([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'item_id' => $made->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    'currency' => $this->store->currency($company), 'is_active' => true, ...array_intersect_key($data, array_flip(self::OWN_FIELDS)),
                ]);
                $record->save();
                $this->audit->record('pharmacy.medicine_created', $record, new: $this->audited($record, $price), actor: $actor, organizationId: $company->getKey());

                return $record;
            }

            $record = $this->store->query(Medicine::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== (int) $data['base_version']) {
                throw PharmacyException::versionConflict(['version' => $record->version]);
            }
            $old = $this->audited($record, $this->item($company, $record)['sale_price_minor']);
            $found = $this->catalog->find($company, 'item', $record->item_id) ?? throw PharmacyException::notFound('medicine');
            $this->catalog->update($company, 'item', $found, (int) $found->version, $item, $actor);
            $record->fill(array_intersect_key($data, array_flip(self::OWN_FIELDS)));
            $record->version++;
            $record->save();
            $this->audit->record('pharmacy.medicine_updated', $record, old: $old, new: $this->audited($record, $price), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /**
     * Medicines with their item, generic and maker, by id or all (active only unless asked).
     *
     * @param  list<string>|null  $ids
     * @return Collection<string, array<string, mixed>>  keyed by medicine id
     */
    public function details(Organization $company, ?array $ids = null, bool $activeOnly = false): Collection
    {
        $medicines = $this->store->query(Medicine::class, $company)
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->get();
        if ($medicines->isEmpty()) {
            return collect();
        }
        $items = collect($this->stock->items($company, $medicines->pluck('item_id')->all(), null, 10000))->keyBy('id');
        $generics = $this->store->query(Generic::class, $company)->whereIn('id', $medicines->pluck('generic_id')->unique()->all())->get()->keyBy('id');
        $makers = $this->store->query(Manufacturer::class, $company)->whereIn('id', $medicines->pluck('manufacturer_id')->filter()->unique()->all())->get()->keyBy('id');

        return $medicines->mapWithKeys(function (Medicine $medicine) use ($items, $generics, $makers) {
            $item = $items[$medicine->item_id] ?? null;
            $generic = $generics[$medicine->generic_id] ?? null;

            return [$medicine->getKey() => [
                'medicine' => $medicine, 'item' => $item, 'generic' => $generic, 'manufacturer' => $makers[$medicine->manufacturer_id] ?? null,
                'is_controlled' => (bool) $generic?->is_controlled,
            ]];
        });
    }

    /**
     * Medicines by brand name, generic name, SKU or barcode.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function search(Organization $company, string $needle, ?string $genericId = null, ?string $form = null): Collection
    {
        $needle = mb_strtolower(trim($needle));

        return $this->details($company)->filter(function (array $row) use ($needle, $genericId, $form) {
            if ($genericId !== null && $row['medicine']->generic_id !== $genericId) {
                return false;
            }
            if ($form !== null && $row['medicine']->form !== $form) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $texts = [...array_values((array) ($row['item']['names'] ?? [])), ...array_values($row['generic']?->texts('name') ?? []), $row['item']['sku'] ?? '', $row['item']['barcode'] ?? ''];

            return collect($texts)->contains(fn ($text) => $text !== null && str_contains(mb_strtolower((string) $text), $needle));
        });
    }

    /**
     * Other brands of the same generic, strength and form (in use), for when one is out of stock.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function substitutes(Organization $company, Medicine $medicine): Collection
    {
        $ids = $this->store->query(Medicine::class, $company)->where('generic_id', $medicine->generic_id)->where('form', $medicine->form)
            ->where('is_active', true)->whereKeyNot($medicine->getKey())
            ->when($medicine->strength !== null, fn ($query) => $query->where('strength', $medicine->strength))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();

        return $ids === [] ? collect() : $this->details($company, $ids);
    }

    /**
     * Pieces in a pack: a strip holds pieces_per_strip, a box strips_per_box strips.
     */
    public static function piecesIn(Medicine $medicine, string $pack): ?int
    {
        return match ($pack) {
            'piece' => 1,
            'strip' => $medicine->pieces_per_strip ?: null,
            'box' => ($medicine->pieces_per_strip ?: 1) * ($medicine->strips_per_box ?: 0) ?: null,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function item(Organization $company, Medicine $medicine): array
    {
        return $this->stock->items($company, [$medicine->item_id])[0] ?? throw PharmacyException::notFound('medicine');
    }

    /** Pieces, kept as whole numbers: the company's unit with code PCS, made once when missing. */
    private function piecesUnit(Organization $company, User $actor): string
    {
        foreach ($this->stock->units($company) as $id => $unit) {
            if (strtoupper((string) $unit['code']) === 'PCS' && (int) $unit['decimals'] === 0) {
                return (string) $id;
            }
        }

        return (string) $this->catalog->create($company, 'unit', ['code' => 'PCS', 'decimals' => 0, 'name' => ['en' => 'Pieces', 'bn' => 'পিস']], $actor)->getKey();
    }

    /** @param  array<string, mixed>  $data */
    private function checkLinks(Organization $company, array $data): void
    {
        if (isset($data['generic_id']) && ! $this->store->query(Generic::class, $company)->whereKey($data['generic_id'])->exists()) {
            throw PharmacyException::badLink('generic_id');
        }
        if (isset($data['manufacturer_id']) && ! $this->store->query(Manufacturer::class, $company)->whereKey($data['manufacturer_id'])->exists()) {
            throw PharmacyException::badLink('manufacturer_id');
        }
    }

    /** @return array<string, mixed> */
    private function audited(Medicine $medicine, ?int $price): array
    {
        return [...array_intersect_key($medicine->attributesToArray(), array_flip(['item_id', ...self::OWN_FIELDS])), 'sale_price_minor' => $price];
    }
}
