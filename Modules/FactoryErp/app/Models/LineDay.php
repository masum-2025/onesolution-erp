<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Who worked on a sewing line on a day (operators, helpers) and for how many minutes.
 */
#[Fillable(['organization_id', 'unit_id', 'line_id', 'worked_on', 'operators', 'helpers', 'minutes', 'note', 'updated_by', 'version'])]
class LineDay extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_line_days';

    protected $attributes = ['helpers' => 0, 'version' => 1];

    protected function casts(): array
    {
        return ['worked_on' => 'immutable_date', 'operators' => 'integer', 'helpers' => 'integer', 'minutes' => 'integer', 'version' => 'integer'];
    }
}
