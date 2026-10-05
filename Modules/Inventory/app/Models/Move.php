<?php

namespace Modules\Inventory\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One movement of stock, never changed: signed quantity and value, what
 * caused it (an inventory document or another module's record).
 */
#[Fillable(['organization_id', 'item_id', 'warehouse_id', 'batch_id', 'kind', 'quantity_milli', 'value_minor', 'moved_on', 'source_module', 'source_type', 'source_id', 'note', 'created_by'])]
class Move extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'inv_moves';

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'value_minor' => 'integer',
            'moved_on' => 'immutable_date',
        ];
    }
}
