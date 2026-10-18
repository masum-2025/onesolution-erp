<?php

namespace Modules\Zkteco\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An office bridge: a program on an office computer that reads the devices
 * on its network for an app hosted online. Known by a token shown once;
 * only its hash is kept.
 */
#[Fillable(['organization_id', 'unit_id', 'name', 'token_hash', 'token_hint', 'last_seen_at', 'last_ip', 'revoked_at', 'created_by'])]
#[Hidden(['token_hash'])]
class Bridge extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'zk_bridges';

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
