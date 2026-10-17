<?php

namespace Modules\Fleet\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Vehicle (made by make:erp-module).
 */
#[Fillable(['organization_id', 'unit_id', 'registration_no', 'model', 'kind', 'capacity_milli', 'bought_on', 'price_minor', 'in_use', 'notes', 'currency', 'created_by', 'op_id', 'version'])]
class Vehicle extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'fleet_vehicles';

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'capacity_milli' => 'integer', 'bought_on' => 'immutable_date', 'price_minor' => 'integer', 'in_use' => 'boolean'];
    }
}
