<?php

namespace Modules\Inventory\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A batch of an item (number, expiry). */
#[Fillable(['organization_id', 'item_id', 'number', 'expires_on'])]
class Batch extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'inv_batches';

    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date',
        ];
    }
}
