<?php

namespace Modules\Attendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The shift an employee works from a day (to a day; open = until changed).
 * A new roster closes the one before it; shift null = no fixed hours.
 */
#[Fillable(['organization_id', 'employee_id', 'shift_id', 'from', 'to', 'created_by'])]
class Roster extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'att_rosters';

    protected function casts(): array
    {
        return ['from' => 'immutable_date', 'to' => 'immutable_date'];
    }
}
