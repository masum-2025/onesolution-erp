<?php

namespace Modules\CourseRegistration\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A student's registration for a session: draft, submitted, approved or returned; credits of the subjects registered (hundredths).
 */
#[Fillable(['organization_id', 'unit_id', 'session_id', 'student_id', 'status', 'credits_centi', 'overload', 'submitted_at', 'submitted_by', 'approved_at', 'approved_by', 'note', 'version'])]
class Registration extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['draft', 'submitted', 'approved', 'returned'];

    protected $table = 'crs_registrations';

    protected function casts(): array
    {
        return ['credits_centi' => 'integer', 'overload' => 'boolean', 'submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
