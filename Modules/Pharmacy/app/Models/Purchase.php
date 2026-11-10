<?php

namespace Modules\Pharmacy\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A medicine purchase: an Inventory receipt (batches and expiry) as the pharmacy made it.
 */
#[Fillable(['organization_id', 'unit_id', 'document_id', 'document_number', 'warehouse_id', 'supplier', 'reference', 'purchased_on', 'total_minor', 'currency', 'line_count', 'created_by', 'op_id'])]
class Purchase extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pharmacy_purchases';

    protected function casts(): array
    {
        return ['purchased_on' => 'immutable_date', 'total_minor' => 'integer', 'line_count' => 'integer'];
    }
}
