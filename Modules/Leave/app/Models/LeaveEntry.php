<?php

namespace Modules\Leave\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of the leave ledger, append-only: half days given (accrual,
 * carried, adjusted, returned) or taken, in a leave year. Each source
 * (an accrual period, a request) is written once.
 */
#[Fillable(['organization_id', 'employee_id', 'type_id', 'leave_year', 'kind', 'halves', 'source', 'request_id', 'note', 'created_by'])]
class LeaveEntry extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const ACCRUAL = 'accrual';

    public const CARRIED = 'carried';

    public const ADJUSTED = 'adjusted';

    public const TAKEN = 'taken';

    public const RETURNED = 'returned';

    public const UPDATED_AT = null;

    protected $table = 'lv_entries';

    protected function casts(): array
    {
        return ['leave_year' => 'integer', 'halves' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
