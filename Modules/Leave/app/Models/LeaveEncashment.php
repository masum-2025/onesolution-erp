<?php

namespace Modules\Leave\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Unused leave to be paid out: half days of an encashable kind, asked by HR,
 * approved by someone else; Payroll pays them in the run of pay_on.
 */
#[Fillable(['organization_id', 'unit_id', 'employee_id', 'type_id', 'leave_year', 'halves', 'reason', 'status', 'requested_by', 'version'])]
class LeaveEncashment extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'lv_encashments';

    protected function casts(): array
    {
        return [
            'leave_year' => 'integer', 'halves' => 'integer', 'decided_at' => 'immutable_datetime',
            'pay_on' => 'immutable_date', 'version' => 'integer',
        ];
    }
}
