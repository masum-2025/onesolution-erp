<?php

namespace Modules\Inventory\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\Inventories;

/**
 * The unit in the address (visible to the context), its company, and
 * warehouses only of the unit and the units below it; anything else is the
 * same 404.
 */
trait FindsInventory
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization}
     */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Inventories::class)->companyOf($unit)];
    }

    /** @return list<string> */
    protected function warehouseIds(Organization $unit, Organization $company): array
    {
        return app(Inventories::class)->query(Warehouse::class, $company)->whereIn('unit_id', app(Inventories::class)->subtreeIds($unit))->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    protected function warehouseIn(Organization $unit, Organization $company, string $id): Warehouse
    {
        $warehouse = app(Inventories::class)->query(Warehouse::class, $company)->whereKey($id)->first();
        if ($warehouse === null || ! in_array($warehouse->unit_id, app(Inventories::class)->subtreeIds($unit), true)) {
            throw InventoryException::notFound('warehouse');
        }

        return $warehouse;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function found(string $class, Organization $company, string $id, string $what): Model
    {
        return app(Inventories::class)->query($class, $company)->whereKey($id)->first() ?? throw InventoryException::notFound($what);
    }

    protected function unitOf(string $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }
}
