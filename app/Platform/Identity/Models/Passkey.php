<?php

namespace App\Platform\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passkey (WebAuthn credential, Phase 8-1): a fingerprint, face or device
 * PIN that signs in without a password and counts as a second step. Bound
 * to the address it was made on (rp_id): a partner's own domain has its own.
 * Only the public key is kept; the private key never leaves the device.
 */
#[Table('user_passkeys')]
#[Hidden(['credential_id', 'credential_hash', 'public_key'])]
class Passkey extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'transports' => 'array',
            'counter' => 'integer',
            'backup_eligible' => 'boolean',
            'backup_status' => 'boolean',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public static function hashId(string $credentialId): string
    {
        return hash('sha256', $credentialId);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
