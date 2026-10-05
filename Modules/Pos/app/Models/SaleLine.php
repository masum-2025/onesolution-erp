<?php

namespace Modules\Pos\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A line of a sale or return: the item as it was, price, discount, tax, cost. */
#[Fillable(['organization_id', 'sale_id', 'line_no', 'item_id', 'sku', 'quantity_milli', 'unit_price_minor', 'discount_minor', 'tax_rate_bp', 'tax_minor', 'net_minor', 'total_minor', 'cost_minor', 'original_line_id', 'returned_milli'])]
class SaleLine extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'pos_sale_lines';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity_milli' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_minor' => 'integer',
            'net_minor' => 'integer',
            'total_minor' => 'integer',
            'cost_minor' => 'integer',
            'returned_milli' => 'integer',
        ];
    }
}
