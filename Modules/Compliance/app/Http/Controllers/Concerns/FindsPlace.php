<?php

namespace Modules\Compliance\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Services\Place;

/**
 * The unit in the address (visible to the context) with the permission
 * checked there; a unit named in the request must be inside it.
 */
trait FindsPlace
{
    use FindsVisibleOrganizations;

    protected function unit(string $organization, string $permission): Organization
    {
        $unit = $this->findVisible($organization);
        Gate::authorize($permission, $unit);

        return $unit;
    }

    /** A unit at or below the one in the address; anything else is the same refusal. */
    protected function unitInside(Organization $unit, ?string $id): Organization
    {
        if ($id === null || $id === $unit->getKey()) {
            return $unit;
        }
        $found = Organization::query()->find($id);
        if ($found === null || ! str_starts_with((string) $found->path, (string) $unit->path)) {
            throw ComplianceException::notFactory();
        }

        return $found;
    }

    /** The company whose clock counts at a unit: its own, or (at a group) the first company below. */
    protected function clockOf(Organization $unit): Organization
    {
        $place = app(Place::class);

        return $unit->type->value === 'group' ? ($place->factories($unit)->first() ?? $unit) : $place->companyOf($unit);
    }
}
