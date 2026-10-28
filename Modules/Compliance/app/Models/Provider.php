<?php

namespace Modules\Compliance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Who carries out audits (a certification body or a coordination firm), possibly connected on the platform; switched off, never removed. */
#[Fillable(['organization_id', 'name', 'email', 'phone', 'connection_id', 'is_active', 'version'])]
class Provider extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'cmp_providers';

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
