<?php

namespace Modules\Zkteco\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A log read from a terminal (once per device, user id and terminal time):
 * recorded into attendance, unmatched (no employee yet) or not_employed.
 */
#[Fillable(['organization_id', 'device_id', 'user_id', 'local_at', 'punched_at', 'status', 'punch', 'employee_id', 'state'])]
class Log extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATES = ['recorded', 'unmatched', 'not_employed'];

    protected $table = 'zk_logs';

    protected function casts(): array
    {
        return ['punched_at' => 'immutable_datetime', 'status' => 'integer', 'punch' => 'integer'];
    }
}
