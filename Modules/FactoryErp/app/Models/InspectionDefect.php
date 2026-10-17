<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * How many defects of one kind an inspection found.
 */
#[Fillable(['organization_id', 'inspection_id', 'defect_type_id', 'count'])]
class InspectionDefect extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_inspection_defects';

    protected function casts(): array
    {
        return ['count' => 'integer'];
    }
}
