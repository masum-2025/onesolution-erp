<?php

namespace Modules\Crm\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of an estimate or quotation: an item or a free description, quantity, price, discount, VAT.
 */
#[Fillable(['organization_id', 'quote_id', 'line_no', 'item_id', 'description', 'quantity_milli', 'unit', 'unit_price_minor', 'discount_minor', 'tax_code_id', 'tax_rate_bp', 'net_minor', 'tax_minor', 'total_minor', 'extra'])]
class QuoteLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public $timestamps = false;

    protected $table = 'crm_quote_lines';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity_milli' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'extra' => 'array',
        ];
    }
}
