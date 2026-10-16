<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What a style is made of: an Inventory item, how much per piece or dozen (thousandths of its unit), wastage in basis points, for every piece or one size and/or colour.
 */
#[Fillable(['organization_id', 'style_id', 'item_id', 'consumption_milli', 'wastage_bp', 'size', 'color', 'note', 'sort_order'])]
class BomLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_bom_lines';

    protected function casts(): array
    {
        return ['consumption_milli' => 'integer', 'wastage_bp' => 'integer', 'sort_order' => 'integer'];
    }
}
