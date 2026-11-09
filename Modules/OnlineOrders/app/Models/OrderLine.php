<?php

namespace Modules\OnlineOrders\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A line of an online order: the item as it was priced, and the cost it left at. */
#[Fillable(['organization_id', 'order_id', 'line_no', 'item_id', 'sku', 'keeps_stock', 'quantity_milli', 'unit_price_minor', 'discount_minor', 'tax_rate_bp', 'tax_minor', 'net_minor', 'total_minor'])]
class OrderLine extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'online_order_lines';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'keeps_stock' => 'boolean',
            'quantity_milli' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_minor' => 'integer',
            'net_minor' => 'integer',
            'total_minor' => 'integer',
            'cost_minor' => 'integer',
        ];
    }
}
