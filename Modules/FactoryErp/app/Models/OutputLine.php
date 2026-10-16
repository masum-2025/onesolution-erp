<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Good pieces and rejects of one size and colour in an output entry.
 */
#[Fillable(['organization_id', 'output_id', 'order_id', 'size', 'color', 'good', 'rejects'])]
class OutputLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_output_lines';

    protected function casts(): array
    {
        return ['good' => 'integer', 'rejects' => 'integer'];
    }
}
