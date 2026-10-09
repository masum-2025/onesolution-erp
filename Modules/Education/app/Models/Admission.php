<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An application and its decision; admitted, it becomes a student and an enrollment.
 */
#[Fillable(['organization_id', 'unit_id', 'number', 'program_id', 'level_id', 'session_id', 'applicant', 'source', 'source_ref', 'status', 'note', 'student_id', 'decided_at', 'decided_by', 'created_by', 'op_id', 'version'])]
class Admission extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['applied', 'test', 'offered', 'admitted', 'rejected', 'withdrawn'];

    /** Decisions that end an application. */
    public const FINAL = ['admitted', 'rejected', 'withdrawn'];

    protected $table = 'edu_admissions';

    protected function casts(): array
    {
        return [
            'applicant' => 'array',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
