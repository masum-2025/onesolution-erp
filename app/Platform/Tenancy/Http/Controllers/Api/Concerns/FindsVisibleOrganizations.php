<?php

namespace App\Platform\Tenancy\Http\Controllers\Api\Concerns;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Exceptions\OrganizationNotFound;
use App\Platform\Tenancy\Models\Organization;

trait FindsVisibleOrganizations
{
    /**
     * Load an organization only if the current context may see it. Anything
     * else (missing, other company, other partner) is the same 404.
     */
    protected function findVisible(string $id): Organization
    {
        return Organization::query()
            ->visibleTo(app(CurrentContext::class))
            ->whereKey($id)
            ->first() ?? throw new OrganizationNotFound;
    }
}
