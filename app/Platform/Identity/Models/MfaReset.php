<?php

namespace App\Platform\Identity\Models;

use App\Models\User;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin's request to clear a member's second steps (Phase 8-1), e.g. a
 * lost phone. A different admin approves it (maker-checker); it lapses
 * after a day. Nothing is mass assignable.
 */
#[Table('mfa_resets')]
class MfaReset extends Model
{
    use BelongsToOrganization, HasUlids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
