<?php

namespace Modules\Inpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
/** Tests sent to the lab for a stay (one lab order, billed by the lab). Append-only. */
#[Fillable(['organization_id', 'admission_id', 'codes', 'lab_order_id', 'lab_order_number', 'ordered_by', 'ordered_at', 'op_id'])]
class TestOrder extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const UPDATED_AT = null;

    protected $table = 'inpatient_test_orders';

    protected function casts(): array
    {
        return ['codes' => 'array', 'ordered_at' => 'datetime'];
    }
}
