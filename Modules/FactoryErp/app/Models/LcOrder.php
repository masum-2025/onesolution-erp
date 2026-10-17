<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An order paid for under a letter of credit (one L/C per order).
 */
#[Fillable(['organization_id', 'lc_id', 'order_id'])]
class LcOrder extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_lc_orders';
}
