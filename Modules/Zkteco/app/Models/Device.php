<?php

namespace Modules\Zkteco\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A ZKTeco terminal on the office network, at a branch. Its comm key is
 * kept encrypted and never sent out.
 */
#[Fillable(['organization_id', 'unit_id', 'bridge_id', 'name', 'host', 'port', 'protocol', 'comm_key', 'import_from', 'is_active', 'serial', 'model', 'users_count', 'logs_count', 'last_attempt_at', 'last_synced_at', 'last_error', 'version'])]
#[Hidden(['comm_key'])]
class Device extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const PROTOCOLS = ['udp', 'tcp'];

    protected $table = 'zk_devices';

    protected $attributes = ['port' => 4370, 'protocol' => 'udp', 'is_active' => true, 'version' => 1];

    protected function casts(): array
    {
        return [
            'comm_key' => 'encrypted', 'import_from' => 'immutable_date', 'is_active' => 'boolean', 'port' => 'integer',
            'users_count' => 'integer', 'logs_count' => 'integer', 'version' => 'integer',
            'last_attempt_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime',
        ];
    }
}
