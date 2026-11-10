<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Referring doctor (made by make:erp-module).
 */
#[Fillable(['organization_id', 'unit_id', 'name', 'registration_no', 'mobile', 'commission_percent', 'is_active', 'created_by', 'op_id', 'version'])]
class Referrer extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'diagnostics_referrers';

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'commission_percent' => 'integer', 'is_active' => 'boolean'];
    }
}
