<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One sewing job of a style's operation bulletin: standard minutes (thousandths), machine, piece rate (minor units).
 */
#[Fillable(['organization_id', 'style_id', 'name', 'machine', 'smv_milli', 'piece_rate_minor', 'sort_order'])]
class Operation extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_operations';

    protected function casts(): array
    {
        return ['smv_milli' => 'integer', 'piece_rate_minor' => 'integer', 'sort_order' => 'integer'];
    }
}
