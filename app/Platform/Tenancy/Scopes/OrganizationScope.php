<?php

namespace App\Platform\Tenancy\Scopes;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Exceptions\MissingTenantContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-scoped model to the organizations the
 * current context may see. Without a context it refuses to run (fail closed).
 *
 * System code that must work across tenants (e.g. a platform job) opts out
 * explicitly with withoutGlobalScope(OrganizationScope::class).
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentContext::class);

        if (! $context->hasOrganization()) {
            throw new MissingTenantContext;
        }

        $builder->whereIn(
            $model->qualifyColumn('organization_id'),
            Organization::query()->select('id')->visibleTo($context),
        );
    }
}
