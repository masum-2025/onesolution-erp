<?php

namespace App\Platform\Portal\Models;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to see one record in a client's portal, bound to an email
 * or phone: only an account that verified that address can use it. Only
 * hashes of the link and the code are stored; the address is encrypted.
 * Nothing is mass assignable.
 *
 * Not tenant-scoped on purpose: it is opened by people who are not members
 * yet. Staff read it only through their context's organization.
 */
#[Table('portal_invitations')]
class PortalInvitation extends Model
{
    use HasUlids;

    public const GUARDIAN = 'guardian';

    public const SELF = 'self';

    protected $guarded = ['*'];

    protected $hidden = ['contact', 'contact_hash', 'token_hash', 'code_hash'];

    protected function casts(): array
    {
        return [
            'contact' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isOpen(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
