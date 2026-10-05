<?php

namespace Modules\Pos\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A sale or a return at a counter, never changed once made. */
#[Fillable(['organization_id', 'register_id', 'session_id', 'unit_id', 'number', 'kind', 'original_sale_id', 'sold_at', 'customer_name', 'reason', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'paid_minor', 'change_minor', 'cost_minor', 'currency_code', 'prices_include_tax', 'offline', 'review_reason', 'created_by', 'op_id', 'version'])]
class Sale extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pos_sales';

    protected function casts(): array
    {
        return [
            'sold_at' => 'immutable_datetime',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'change_minor' => 'integer',
            'cost_minor' => 'integer',
            'prices_include_tax' => 'boolean',
            'offline' => 'boolean',
            'version' => 'integer',
        ];
    }
}
