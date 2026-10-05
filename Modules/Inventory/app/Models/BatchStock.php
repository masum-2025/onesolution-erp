<?php

namespace Modules\Inventory\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** How much of a batch sits in a warehouse. */
#[Fillable(['organization_id', 'batch_id', 'warehouse_id', 'quantity_milli'])]
class BatchStock extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'inv_batch_stock';

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
        ];
    }
}
