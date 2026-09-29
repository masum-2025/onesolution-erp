<?php

namespace App\Platform\Offline\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A lease the server signed for a device: what it may do offline, until
 * when. The signed token travels to the device; this row lets the server
 * check that an operation was made under a lease it really issued, in time.
 * Nothing is mass assignable.
 *
 * Not tenant-scoped on purpose (checked before any context, like Device).
 */
#[Table('offline_leases')]
class OfflineLease extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'rule_keys' => 'array',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
