<?php

namespace Modules\Leave\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Asking for leave: the days (half days at either end), how many half days
 * that is, the part without pay, and where the approval steps stand.
 */
#[Fillable([
    'organization_id', 'unit_id', 'employee_id', 'type_id', 'leave_year', 'from_date', 'to_date', 'from_half', 'to_half',
    'halves', 'unpaid_halves', 'reason', 'status', 'steps_needed', 'steps_done', 'requested_by', 'op_id', 'version',
])]
class LeaveRequest extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $table = 'lv_requests';

    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date', 'to_date' => 'immutable_date', 'from_half' => 'boolean', 'to_half' => 'boolean',
            'leave_year' => 'integer', 'halves' => 'integer', 'unpaid_halves' => 'integer', 'steps_needed' => 'integer', 'steps_done' => 'integer',
            'decided_at' => 'immutable_datetime', 'version' => 'integer',
        ];
    }
}
