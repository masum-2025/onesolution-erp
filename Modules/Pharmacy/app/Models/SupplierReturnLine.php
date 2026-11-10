<?php

namespace Modules\Pharmacy\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A batch of a medicine in a supplier return.
 */
#[Fillable(['organization_id', 'return_id', 'medicine_id', 'batch_number', 'expires_on', 'quantity_milli'])]
class SupplierReturnLine extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pharmacy_supplier_return_lines';

    protected function casts(): array
    {
        return ['expires_on' => 'immutable_date', 'quantity_milli' => 'integer'];
    }
}
