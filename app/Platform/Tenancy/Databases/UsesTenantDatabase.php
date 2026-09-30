<?php

namespace App\Platform\Tenancy\Databases;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Business data that lives in its client's database (Phase 10): shared by
 * default, or a dedicated / regional one (TenantDatabases). Use together with
 * BelongsToOrganization. The table's migration goes in a
 * database/migrations/tenant folder and has no foreign keys to platform
 * tables (enforced by tests/Feature/Architecture/TenantDatabaseTablesTest).
 *
 * @mixin Model
 */
trait UsesTenantDatabase
{
    public static function bootUsesTenantDatabase(): void
    {
        static::saving(function (Model $model) {
            app(TenantDatabases::class)->assertWritable($model);

            // Remember where a new record was written, for later updates.
            if (! $model->exists) {
                $model->setConnection($model->getConnectionName());
            }
        });
        static::deleting(fn (Model $model) => app(TenantDatabases::class)->assertWritable($model));
    }

    /**
     * A loaded record keeps the connection it came from. A new record with an
     * organization always asks TenantDatabases: static::create() builds it
     * from a bare query model, which may have picked another database before
     * the organization was known. Anything else asks too.
     */
    public function getConnectionName()
    {
        if ($this->connection === null || (! $this->exists && $this->getAttribute('organization_id'))) {
            return app(TenantDatabases::class)->forModel($this);
        }

        return $this->connection;
    }

    /**
     * Query one client's database from system code (no tenant context). The
     * organization scope still applies unless removed explicitly.
     *
     * @return Builder<static>
     */
    public static function inTenantOf(Organization|string $organization): Builder
    {
        return static::on(app(TenantDatabases::class)->forOrganization($organization));
    }
}
