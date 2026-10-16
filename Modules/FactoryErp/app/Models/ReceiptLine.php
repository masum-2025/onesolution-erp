<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Finished pieces of one size and colour in a receipt.
 */
#[Fillable(['organization_id', 'receipt_id', 'order_id', 'size', 'color', 'quantity'])]
class ReceiptLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fac_receipt_lines';

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
