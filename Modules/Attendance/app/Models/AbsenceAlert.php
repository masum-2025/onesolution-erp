<?php

namespace Modules\Attendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * HR was told that an employee has been absent for many working days in a
 * row (rule attendance.absent_alert_days). One row per streak, so the same
 * streak is never reported twice.
 */
#[Fillable(['organization_id', 'unit_id', 'employee_id', 'streak_from', 'days', 'sent_on'])]
class AbsenceAlert extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'att_absence_alerts';

    protected function casts(): array
    {
        return [
            'streak_from' => 'immutable_date',
            'sent_on' => 'immutable_date',
            'days' => 'integer',
        ];
    }
}
