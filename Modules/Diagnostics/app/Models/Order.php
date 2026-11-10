<?php

namespace Modules\Diagnostics\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A patient's test order at a branch, billed on the patient's bill. Its
 * status follows its tests: ordered → collected → resulted → verified →
 * delivered (or cancelled).
 */
#[Fillable([
    'organization_id', 'unit_id', 'number', 'patient_id', 'referrer_id', 'source_module', 'source_id', 'bill_id', 'status', 'notes',
    'due_at', 'delivered_at', 'created_by', 'op_id', 'version',
])]
class Order extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    /** In the order a report moves through the lab. */
    public const STAGES = ['ordered', 'collected', 'resulted', 'verified', 'delivered'];

    protected $table = 'diagnostics_orders';

    protected $attributes = ['version' => 1, 'status' => 'ordered'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'due_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }
}
