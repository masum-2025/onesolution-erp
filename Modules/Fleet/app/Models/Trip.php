<?php

namespace Modules\Fleet\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Trip (made by make:erp-module).
 */
#[Fillable(['organization_id', 'unit_id', 'purpose', 'vehicle_id', 'driver_id', 'trip_on', 'destination', 'distance_milli', 'fuel_cost_minor', 'currency', 'created_by', 'op_id', 'version'])]
class Trip extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fleet_trips';

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'trip_on' => 'immutable_date', 'distance_milli' => 'integer', 'fuel_cost_minor' => 'integer'];
    }
}
