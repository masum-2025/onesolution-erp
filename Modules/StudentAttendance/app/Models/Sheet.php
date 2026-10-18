<?php

namespace Modules\StudentAttendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One section's attendance on one day: for the whole day (slot "day") or
 * for one period (slot = the period's id), with how many were in each state.
 */
#[Fillable(['organization_id', 'unit_id', 'session_id', 'section_id', 'taken_on', 'slot', 'period_id', 'subject_id', 'teacher_id', 'taken_by', 'present_count', 'absent_count', 'late_count', 'leave_count', 'op_id', 'version'])]
class Sheet extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    /** The slot of a whole-day sheet. */
    public const DAY = 'day';

    protected $table = 'sta_sheets';

    protected $attributes = ['version' => 1, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'leave_count' => 0];

    protected function casts(): array
    {
        return [
            'taken_on' => 'immutable_date', 'version' => 'integer',
            'present_count' => 'integer', 'absent_count' => 'integer', 'late_count' => 'integer', 'leave_count' => 'integer',
        ];
    }
}
