<?php

namespace Modules\FactoryErp\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Style;
use Modules\FactoryErp\Services\Floor;

/**
 * The unit in the address (visible to the context), its company, a style of
 * the company, and orders of the unit and the units below it; anything
 * else is the same 404.
 */
trait FindsFactory
{
    use FindsVisibleOrganizations;

    /** @return array{0: Organization, 1: Organization} */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Floor::class)->companyOf($unit)];
    }

    protected function styleOf(Organization $company, string $id): Style
    {
        return app(Floor::class)->query(Style::class, $company)->whereKey($id)->first() ?? throw FactoryException::notFound('style');
    }

    protected function orderIn(Organization $unit, Organization $company, string $id): Order
    {
        return app(Floor::class)->query(Order::class, $company)->whereKey($id)->whereIn('unit_id', app(Floor::class)->subtreeIds($unit))->first()
            ?? throw FactoryException::notFound('order');
    }
}
