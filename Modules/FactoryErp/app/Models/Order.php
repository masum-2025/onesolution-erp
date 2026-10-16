<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A buyer's order (PO) for a style: ship date, price per piece in minor units of its currency, pieces per size and colour (OrderLine).
 */
#[Fillable(['organization_id', 'unit_id', 'number', 'buyer_id', 'style_id', 'po_number', 'ship_on', 'currency', 'unit_price_minor', 'status', 'note', 'extra', 'cancelled_reason', 'created_by', 'confirmed_at', 'op_id', 'version'])]
class Order extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['draft', 'confirmed', 'in_production', 'shipped', 'cancelled'];

    protected $table = 'fac_orders';

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return ['extra' => 'array', 'ship_on' => 'immutable_date', 'unit_price_minor' => 'integer', 'confirmed_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
