<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A buyer's style: its sizes and colours in order, and whether consumption is written per piece or per dozen.
 */
#[Fillable(['organization_id', 'buyer_id', 'code', 'name', 'product_type', 'sizes', 'colors', 'consumption_basis', 'note', 'extra', 'is_active', 'version'])]
class Style extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const BASES = ['piece', 'dozen'];

    protected $table = 'fac_styles';

    protected $attributes = ['version' => 1, 'is_active' => true];

    protected function casts(): array
    {
        return ['sizes' => 'array', 'colors' => 'array', 'extra' => 'array', 'is_active' => 'boolean', 'version' => 'integer'];
    }
}
