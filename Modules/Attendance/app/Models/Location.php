<?php

namespace Modules\Attendance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A workplace of a unit (and the units below it): a point in millionths of
 * a degree and a radius in metres. Checking in counts only inside one, when
 * the unit asks for a location check. Switched off, never removed.
 */
#[Fillable(['organization_id', 'unit_id', 'name', 'latitude_micro', 'longitude_micro', 'radius_m', 'is_active', 'version'])]
class Location extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'att_locations';

    protected function casts(): array
    {
        return ['latitude_micro' => 'integer', 'longitude_micro' => 'integer', 'radius_m' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }
}
