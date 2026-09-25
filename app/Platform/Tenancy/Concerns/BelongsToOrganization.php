<?php

namespace App\Platform\Tenancy\Concerns;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Exceptions\MissingTenantContext;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Exceptions\OrganizationChangeForbidden;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Makes a model tenant-scoped. Every business model must use this trait
 * (enforced by tests/Feature/Architecture/TenantModelsTest).
 *
 * - reads: limited to the context's visible organizations (OrganizationScope)
 * - create: organization_id comes from the context; an explicit value must be
 *   writable from the context
 * - update: organization_id can never change; row must be writable
 * - delete: row must be writable
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model) {
            $context = app(CurrentContext::class);

            if (! $context->hasOrganization()) {
                // Server-side system code (seeders, platform jobs) must name the organization.
                if (empty($model->getAttribute('organization_id'))) {
                    throw new MissingTenantContext;
                }

                return;
            }

            if (empty($model->getAttribute('organization_id'))) {
                $model->setAttribute('organization_id', $context->organization()->getKey());
            }

            if (! $context->canWriteTo($model->getAttribute('organization_id'))) {
                throw OrganizationAccessDenied::writeOutsideScope();
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('organization_id')) {
                throw new OrganizationChangeForbidden;
            }

            static::assertWritableFromContext($model);
        });

        static::deleting(fn (Model $model) => static::assertWritableFromContext($model));
    }

    protected static function assertWritableFromContext(Model $model): void
    {
        $context = app(CurrentContext::class);

        if ($context->hasOrganization() && ! $context->canWriteTo($model->getAttribute('organization_id'))) {
            throw OrganizationAccessDenied::writeOutsideScope();
        }
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
