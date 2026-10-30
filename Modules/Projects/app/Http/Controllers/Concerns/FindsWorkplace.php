<?php

namespace Modules\Projects\Http\Controllers\Concerns;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Modules\Projects\Exceptions\ProjectsException;

/**
 * The unit in the address (visible to the context) with the permission
 * checked there; a unit named in the request must be inside it; writing
 * happens from the unit itself or above it (a group reads only).
 */
trait FindsWorkplace
{
    use FindsVisibleOrganizations;

    protected function unit(string $organization, string $permission): Organization
    {
        $unit = $this->findVisible($organization);
        Gate::authorize($permission, $unit);

        return $unit;
    }

    /** The unit in the address with any of the permissions there. */
    protected function unitWithAny(string $organization, array $permissions): Organization
    {
        $unit = $this->findVisible($organization);
        if (! collect($permissions)->contains(fn (string $permission) => Gate::allows($permission, $unit))) {
            throw new AuthorizationException(__('tenancy.errors.forbidden'));
        }

        return $unit;
    }

    protected function unitInside(Organization $unit, ?string $id): Organization
    {
        if ($id === null || $id === $unit->getKey()) {
            return $unit;
        }
        $found = Organization::query()->find($id);
        if ($found === null || ! str_starts_with((string) $found->path, (string) $unit->path)) {
            throw ProjectsException::notWorkplace();
        }

        return $found;
    }

    /** Written from here (never by a group into its companies' data) and allowed there. */
    protected function writable(string $organizationId, string $permission): Organization
    {
        $unit = Organization::query()->findOrFail($organizationId);
        if (! app(CurrentContext::class)->canWriteTo($unit->getKey())) {
            throw OrganizationAccessDenied::writeOutsideScope();
        }
        Gate::authorize($permission, $unit);

        return $unit;
    }
}
