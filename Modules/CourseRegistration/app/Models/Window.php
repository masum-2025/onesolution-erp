<?php

namespace Modules\CourseRegistration\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * When students of a session register (opens, closes) and until when they may add and drop subjects.
 */
#[Fillable(['organization_id', 'session_id', 'opens_on', 'closes_on', 'add_drop_until', 'created_by', 'version'])]
class Window extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'crs_windows';

    protected function casts(): array
    {
        return ['opens_on' => 'immutable_date', 'closes_on' => 'immutable_date', 'add_drop_until' => 'immutable_date', 'version' => 'integer'];
    }
}
