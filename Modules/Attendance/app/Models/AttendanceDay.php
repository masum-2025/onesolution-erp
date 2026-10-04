<?php

namespace Modules\Attendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Attendance\Enums\DayStatus;

/**
 * One employee's day, worked out from their shift, days off and punches:
 * status, first in and last out, and minutes worked, late and over time.
 * Recalculated whenever something about that day changes.
 */
#[Fillable([
    'organization_id', 'unit_id', 'employee_id', 'work_date', 'shift_id', 'status',
    'first_in_at', 'last_out_at', 'worked_minutes', 'late_minutes', 'overtime_minutes',
])]
class AttendanceDay extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'att_days';

    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'status' => DayStatus::class,
            'first_in_at' => 'immutable_datetime',
            'last_out_at' => 'immutable_datetime',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'overtime_minutes' => 'integer',
        ];
    }
}
