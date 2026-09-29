<?php

namespace App\Platform\Offline\Models;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser or app a person uses offline for one organization (Phase 7).
 * A revoked device gets no lease and no sync; on its next contact it is told
 * to wipe its local data. Nothing is mass assignable.
 *
 * Not tenant-scoped on purpose: the sync endpoint checks it before any
 * tenant context exists (a revoked person has none). People read devices
 * only through their own account or their context's organization.
 */
#[Table('devices')]
class Device extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'last_sync_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'wipe_requested_at' => 'immutable_datetime',
            'wiped_at' => 'immutable_datetime',
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

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Must clear its local data (revoked, or offline mode turned off) and has not confirmed yet. */
    public function mustWipe(): bool
    {
        return $this->wipe_requested_at !== null && $this->wiped_at === null;
    }
}
