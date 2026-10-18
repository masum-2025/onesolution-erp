<?php

namespace Modules\Zkteco\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A terminal's user (its user id and name) and the HRM employee it is: by code, or matched by hand. */
#[Fillable(['organization_id', 'device_id', 'device_uid', 'user_id', 'name', 'employee_id', 'matched_by'])]
class DeviceUser extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'zk_device_users';

    protected function casts(): array
    {
        return ['device_uid' => 'integer'];
    }
}
