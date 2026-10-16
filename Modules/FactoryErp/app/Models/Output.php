<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What a line sewed for an order in an hour (or a day): good pieces and rejects (per size and colour in OutputLine); voided with a reason, never deleted.
 */
#[Fillable(['organization_id', 'unit_id', 'line_id', 'order_id', 'worked_on', 'hour', 'good', 'rejects', 'created_by', 'voided_at', 'voided_by', 'void_reason', 'op_id', 'version'])]
class Output extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $attributes = ['version' => 1];

    protected $table = 'fac_outputs';

    protected function casts(): array
    {
        return ['worked_on' => 'immutable_date', 'hour' => 'integer', 'good' => 'integer', 'rejects' => 'integer', 'voided_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
