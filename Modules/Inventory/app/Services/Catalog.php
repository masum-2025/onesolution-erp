<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Move;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;

/**
 * Units, categories, items and warehouses of a company: created and changed
 * with the version seen, switched off rather than removed, audited. Codes,
 * SKUs and barcodes are unique in the company. Once an item has moved, its
 * unit and whether it keeps stock no longer change.
 */
class Catalog
{
    private const KINDS = [
        'unit' => [Unit::class, ['code', 'decimals', 'is_active']],
        'category' => [Category::class, ['code', 'parent_id', 'is_active']],
        'warehouse' => [Warehouse::class, ['code', 'unit_id', 'is_active']],
        'item' => [Item::class, ['sku', 'barcode', 'category_id', 'unit_id', 'kind', 'track_batches', 'sale_price_minor', 'tax_code_id', 'reorder_level_milli', 'reorder_quantity_milli', 'description', 'is_active']],
    ];

    public function __construct(private Inventories $inventories, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Organization $company, string $kind, array $data, User $actor): Model
    {
        [$class, $fields] = self::KINDS[$kind];
        $this->checkReferences($company, $kind, $data, null);

        return $this->inventories->transaction($company, function () use ($company, $kind, $class, $fields, $data, $actor) {
            /** @var Model $model */
            $model = new $class;
            $model->fill(['organization_id' => $company->getKey(), 'version' => 1, 'is_active' => true, ...array_intersect_key($data, array_flip($fields))]);
            $model->putTexts('name', $data['name']);
            $model->save();
            $this->audit->record("inventory.{$kind}_created", $model, new: $this->values($model, $fields), actor: $actor, organizationId: $company->getKey());

            return $model;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $company, string $kind, Model $model, int $baseVersion, array $data, User $actor): Model
    {
        [$class, $fields] = self::KINDS[$kind];
        $this->checkReferences($company, $kind, $data, $model);

        return $this->inventories->transaction($company, function () use ($company, $kind, $class, $fields, $model, $baseVersion, $data, $actor) {
            $fresh = $this->inventories->query($class, $company)->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->version !== $baseVersion) {
                throw InventoryException::versionConflict(['version' => $fresh->version]);
            }
            if ($kind === 'item' && $this->hasMoved($company, $fresh) && ((isset($data['unit_id']) && $data['unit_id'] !== $fresh->unit_id) || (isset($data['kind']) && $data['kind'] !== $fresh->kind))) {
                throw ValidationException::withMessages(['unit_id' => __('inventory::inventory.validation.item_moved')]);
            }
            $old = $this->values($fresh, $fields);
            $fresh->fill(array_intersect_key($data, array_flip($fields)));
            if (isset($data['name'])) {
                $fresh->putTexts('name', $data['name']);
            }
            $fresh->version++;
            $fresh->save();
            $this->audit->record("inventory.{$kind}_updated", $fresh, old: $old, new: $this->values($fresh, $fields), actor: $actor, organizationId: $company->getKey());

            return $fresh;
        });
    }

    public function hasMoved(Organization $company, Item $item): bool
    {
        return $this->inventories->query(Move::class, $company)->where('item_id', $item->getKey())->exists();
    }

    /**
     * Codes unique in the company; the unit, category and warehouse unit theirs.
     *
     * @param  array<string, mixed>  $data
     */
    private function checkReferences(Organization $company, string $kind, array $data, ?Model $current): void
    {
        [$class] = self::KINDS[$kind];
        $taken = function (string $field) use ($company, $class, $data, $current) {
            if (! isset($data[$field]) || $data[$field] === null) {
                return false;
            }

            return $this->inventories->query($class, $company)->where($field, $data[$field])
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))->exists();
        };
        $errors = [];
        foreach ($kind === 'item' ? ['sku', 'barcode'] : ['code'] as $field) {
            if ($taken($field)) {
                $errors[$field] = __('inventory::inventory.validation.taken');
            }
        }
        if (isset($data['unit_id']) && $kind === 'item' && ! $this->inventories->query(Unit::class, $company)->whereKey($data['unit_id'])->where('is_active', true)->exists()) {
            $errors['unit_id'] = __('inventory::inventory.validation.unit');
        }
        if (! empty($data['category_id']) && ! $this->inventories->query(Category::class, $company)->whereKey($data['category_id'])->exists()) {
            $errors['category_id'] = __('inventory::inventory.validation.category');
        }
        if (! empty($data['parent_id']) && ($current?->getKey() === $data['parent_id'] || ! $this->inventories->query(Category::class, $company)->whereKey($data['parent_id'])->exists())) {
            $errors['parent_id'] = __('inventory::inventory.validation.category');
        }
        if ($kind === 'warehouse' && isset($data['unit_id']) && ! in_array($data['unit_id'], $this->inventories->subtreeIds($company), true)) {
            $errors['unit_id'] = __('inventory::inventory.validation.warehouse_unit');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function values(Model $model, array $fields): array
    {
        return [...$model->only($fields), 'name' => $model->texts('name')];
    }
}
