<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A student in a session, level and section. Never rewritten for a new period: each has its own row.
 */
#[Fillable(['organization_id', 'unit_id', 'student_id', 'session_id', 'level_id', 'section_id', 'roll_no', 'status', 'started_on', 'ended_on', 'promotion_line_id', 'version'])]
class Enrollment extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['active', 'promoted', 'repeated', 'left', 'graduated', 'transferred'];

    protected $table = 'edu_enrollments';

    protected function casts(): array
    {
        return [
            'roll_no' => 'integer',
            'started_on' => 'immutable_date',
            'ended_on' => 'immutable_date',
            'version' => 'integer',
        ];
    }
}
