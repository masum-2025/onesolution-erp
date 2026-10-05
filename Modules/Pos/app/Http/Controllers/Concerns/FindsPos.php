<?php

namespace Modules\Pos\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Pos\Exceptions\PosException;
use Modules\Pos\Models\Register;
use Modules\Pos\Services\Tills;

/**
 * The unit in the address (visible to the context), its company, and
 * counters only of the unit and the units below it; anything else is the
 * same 404.
 */
trait FindsPos
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization}
     */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Tills::class)->companyOf($unit)];
    }

    /** @return list<string> */
    protected function registerIds(Organization $unit, Organization $company): array
    {
        return app(Tills::class)->query(Register::class, $company)->whereIn('unit_id', app(Tills::class)->subtreeIds($unit))->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    protected function registerIn(Organization $unit, Organization $company, string $id): Register
    {
        $register = app(Tills::class)->query(Register::class, $company)->whereKey($id)->first();
        if ($register === null || ! in_array($register->unit_id, app(Tills::class)->subtreeIds($unit), true)) {
            throw PosException::notFound('register');
        }

        return $register;
    }

    protected function unitOf(string $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }
}
