<?php

namespace Modules\Outpatient\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A doctor's weekly session at a chamber: a weekday (0 = Sunday), a start and
 * end time in the company's time zone, and how many serials it takes (empty =
 * rule outpatient.daily_serial_limit). A session no longer held is switched
 * off, never removed, so its serials keep their link.
 */
#[Fillable(['organization_id', 'doctor_id', 'chamber_id', 'weekday', 'starts_at', 'ends_at', 'serial_limit', 'is_active'])]
class Schedule extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'outpatient_schedules';

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'serial_limit' => 'integer', 'is_active' => 'boolean'];
    }
}
