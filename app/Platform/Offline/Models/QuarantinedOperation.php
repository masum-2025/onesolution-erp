<?php

namespace App\Platform\Offline\Models;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An offline change from a device or person no longer allowed (revoked
 * device, membership ended, permission gone), held for someone with
 * offline_mode.manage to release (apply it now, as a fresh request) or
 * discard. The operation itself is stored encrypted. Nothing is mass
 * assignable.
 *
 * Not tenant-scoped on purpose: written before any context exists; read
 * only through the context's organization.
 */
#[Table('sync_quarantine')]
class QuarantinedOperation extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const RELEASED = 'released';

    public const DISCARDED = 'discarded';

    protected $guarded = ['*'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'money' => 'boolean',
            'decision_result' => 'array',
            'decided_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
