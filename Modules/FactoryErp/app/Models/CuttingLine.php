<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pieces of one size and colour cut in a lay.
 */
#[Fillable(['organization_id', 'cutting_id', 'order_id', 'size', 'color', 'quantity'])]
class CuttingLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_cutting_lines';

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
