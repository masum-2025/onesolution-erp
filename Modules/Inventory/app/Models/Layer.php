<?php

namespace Modules\Inventory\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** What is left of one receipt, consumed oldest first under FIFO. */
#[Fillable(['organization_id', 'item_id', 'warehouse_id', 'move_id', 'remaining_milli', 'remaining_value_minor'])]
class Layer extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'inv_layers';

    protected function casts(): array
    {
        return [
            'remaining_milli' => 'integer',
            'remaining_value_minor' => 'integer',
        ];
    }
}
