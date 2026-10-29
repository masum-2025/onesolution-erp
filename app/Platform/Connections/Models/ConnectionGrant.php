<?php

namespace App\Platform\Connections\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What a client lets a connected provider do in one module: the module's own
 * permissions, until when, unless taken back. Nothing is mass assignable.
 */
#[Table('business_connection_grants')]
class ConnectionGrant extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** In force now: not taken back, not past its day. */
    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
