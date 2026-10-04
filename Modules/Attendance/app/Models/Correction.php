<?php

namespace Modules\Attendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A forgotten or wrong punch: the times it should have been, with a reason.
 * Another person approves (the times become punches) or rejects it.
 */
#[Fillable(['organization_id', 'unit_id', 'employee_id', 'work_date', 'in_at', 'out_at', 'reason', 'status', 'requested_by', 'version'])]
class Correction extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'att_corrections';

    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'in_at' => 'immutable_datetime',
            'out_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
