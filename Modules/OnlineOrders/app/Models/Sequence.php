<?php

namespace Modules\OnlineOrders\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** The next order number per company and year. */
#[Fillable(['organization_id', 'year', 'next'])]
class Sequence extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'online_order_sequences';

    protected function casts(): array
    {
        return ['year' => 'integer', 'next' => 'integer'];
    }
}
