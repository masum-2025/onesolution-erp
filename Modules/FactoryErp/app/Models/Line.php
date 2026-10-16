<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A sewing line of a factory (branch): code, name, machines; switched off rather than removed.
 */
#[Fillable(['organization_id', 'unit_id', 'code', 'name', 'machines', 'is_active', 'version'])]
class Line extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $attributes = ['is_active' => true, 'version' => 1];

    protected $table = 'fac_lines';

    protected function casts(): array
    {
        return ['machines' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }
}
