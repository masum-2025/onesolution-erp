<?php

namespace Modules\Payroll\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** One component of a structure: a fixed amount, or a share of the basic in basis points. */
#[Fillable(['organization_id', 'structure_id', 'component_id', 'calc', 'amount_minor', 'rate_bp', 'sort'])]
class StructureItem extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const CALCS = ['fixed', 'percent_of_basic'];

    protected $table = 'pay_structure_items';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'rate_bp' => 'integer', 'sort' => 'integer'];
    }
}
