<?php

namespace Modules\Inventory\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Quantity and value of an item in a warehouse, kept in step with the
 * moves. The valuation method is fixed when the balance starts.
 */
#[Fillable(['organization_id', 'item_id', 'warehouse_id', 'quantity_milli', 'value_minor', 'last_unit_cost_minor', 'method', 'version'])]
class Balance extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'inv_balances';

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'value_minor' => 'integer',
            'last_unit_cost_minor' => 'integer',
            'version' => 'integer',
        ];
    }
}
