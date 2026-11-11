<?php

namespace Modules\Outpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Chamber (made by make:erp-module).
 */
#[Fillable(['organization_id', 'unit_id', 'name', 'room', 'is_active', 'created_by', 'op_id', 'version'])]
class Chamber extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'outpatient_chambers';

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean'];
    }
}
