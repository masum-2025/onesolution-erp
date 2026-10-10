<?php

namespace Modules\CourseRegistration\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One subject of a registration, never deleted: registered, waitlisted, dropped or withdrawn, and its outcome once known.
 */
#[Fillable(['organization_id', 'registration_id', 'student_id', 'session_id', 'offering_id', 'subject_id', 'credits_centi', 'status', 'source', 'waitlisted_at', 'registered_at', 'ended_at', 'reason', 'outcome', 'outcome_by', 'outcome_at', 'created_by', 'op_id'])]
class RegistrationItem extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['registered', 'waitlisted', 'dropped', 'withdrawn'];

    /** Items that count: holding a seat or waiting for one. */
    public const ACTIVE = ['registered', 'waitlisted'];

    public const OUTCOMES = ['completed', 'failed', 'incomplete', 'withdrawn'];

    protected $table = 'crs_registration_items';

    protected function casts(): array
    {
        return ['credits_centi' => 'integer', 'waitlisted_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'outcome_at' => 'immutable_datetime'];
    }
}
