<?php

namespace Modules\StudentAttendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A family told about a student's day (absent, or a skipped period), once per student, day and kind. */
#[Fillable(['organization_id', 'student_id', 'taken_on', 'kind', 'sheet_id', 'messages'])]
class Notice extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'sta_notices';

    protected function casts(): array
    {
        return ['taken_on' => 'immutable_date', 'messages' => 'integer'];
    }
}
