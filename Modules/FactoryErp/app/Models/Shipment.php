<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Finished goods of an order leaving for the buyer (packing list in ShipmentCarton); draft, then shipped and fixed.
 */
#[Fillable(['organization_id', 'unit_id', 'order_id', 'number', 'ship_on', 'mode', 'carrier', 'transport_ref', 'destination', 'warehouse_id', 'currency', 'rate_micro', 'is_last', 'status', 'pieces', 'value_minor', 'cost_minor', 'invoice_id', 'journal_id', 'note', 'created_by', 'shipped_at', 'op_id', 'version'])]
class Shipment extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const MODES = ['sea', 'air', 'road'];

    protected $table = 'fac_shipments';

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return [
            'ship_on' => 'immutable_date', 'rate_micro' => 'integer', 'is_last' => 'boolean', 'pieces' => 'integer', 'value_minor' => 'integer',
            'cost_minor' => 'integer', 'shipped_at' => 'immutable_datetime', 'version' => 'integer',
        ];
    }
}
