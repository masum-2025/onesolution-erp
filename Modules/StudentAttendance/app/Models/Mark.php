<?php

namespace Modules\StudentAttendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A student's state on a sheet: present, absent, late or on leave, with a short note. */
#[Fillable(['organization_id', 'sheet_id', 'student_id', 'taken_on', 'status', 'note'])]
class Mark extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['present', 'absent', 'late', 'leave'];

    protected $table = 'sta_marks';

    protected function casts(): array
    {
        return ['taken_on' => 'immutable_date'];
    }
}
