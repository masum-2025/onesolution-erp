<?php

namespace Modules\FactoryErp\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Materials from a store to the floor for an order (issue) or back (return), at what Inventory valued them. Append-only.
 */
#[Fillable(['organization_id', 'unit_id', 'order_id', 'warehouse_id', 'kind', 'issued_on', 'cost_minor', 'currency', 'journal_id', 'note', 'created_by', 'op_id'])]
class Issue extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['issue', 'return'];

    protected $table = 'fac_issues';

    protected function casts(): array
    {
        return ['issued_on' => 'immutable_date', 'cost_minor' => 'integer'];
    }
}
