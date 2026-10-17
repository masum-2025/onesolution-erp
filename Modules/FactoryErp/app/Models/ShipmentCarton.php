<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A run of cartons of a shipment (numbers from-to), each with pieces of one size and colour, weights in grams, size in mm.
 */
#[Fillable(['organization_id', 'shipment_id', 'order_id', 'from_no', 'to_no', 'size', 'color', 'pieces_each', 'net_grams_each', 'gross_grams_each', 'length_mm', 'width_mm', 'height_mm', 'sort_order'])]
class ShipmentCarton extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_shipment_cartons';

    protected function casts(): array
    {
        return [
            'from_no' => 'integer', 'to_no' => 'integer', 'pieces_each' => 'integer', 'net_grams_each' => 'integer', 'gross_grams_each' => 'integer',
            'length_mm' => 'integer', 'width_mm' => 'integer', 'height_mm' => 'integer', 'sort_order' => 'integer',
        ];
    }

    /** How many cartons the run is. */
    public function cartonCount(): int
    {
        return $this->to_no - $this->from_no + 1;
    }

    /** Pieces in the whole run. */
    public function totalPieces(): int
    {
        return $this->cartonCount() * $this->pieces_each;
    }
}
