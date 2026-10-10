<?php

namespace Modules\CourseRegistration\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A subject taught in a session at a campus, in a group, with a teacher, seats and credits (hundredths).
 */
#[Fillable(['organization_id', 'unit_id', 'session_id', 'level_id', 'subject_id', 'group_name', 'kind', 'teacher_id', 'capacity', 'credits_centi', 'status', 'note', 'version'])]
class Offering extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const KINDS = ['compulsory', 'elective', 'optional'];

    public const STATUSES = ['open', 'closed', 'cancelled'];

    protected $table = 'crs_offerings';

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'credits_centi' => 'integer', 'version' => 'integer'];
    }
}
