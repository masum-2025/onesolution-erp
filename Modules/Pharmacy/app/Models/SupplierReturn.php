<?php

namespace Modules\Pharmacy\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock going back to a supplier: submitted, then approved by another person (an Inventory issue) or rejected.
 */
#[Fillable(['organization_id', 'unit_id', 'number', 'warehouse_id', 'supplier', 'reason', 'status', 'document_id', 'created_by', 'decided_by', 'decided_at', 'op_id', 'version'])]
class SupplierReturn extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'pharmacy_supplier_returns';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'decided_at' => 'immutable_datetime'];
    }
}
