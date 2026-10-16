<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Finished pieces of an order into a store as the style's finished item, at their share of the order's work in progress. Append-only.
 */
#[Fillable(['organization_id', 'unit_id', 'order_id', 'warehouse_id', 'item_id', 'received_on', 'pieces', 'is_last', 'cost_minor', 'currency', 'journal_id', 'note', 'created_by', 'op_id'])]
class Receipt extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_receipts';

    protected function casts(): array
    {
        return ['received_on' => 'immutable_date', 'pieces' => 'integer', 'is_last' => 'boolean', 'cost_minor' => 'integer'];
    }
}
