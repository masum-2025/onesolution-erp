<?php

namespace App\Platform\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * One one-time code sent to an email or phone. Only a keyed hash of the code
 * and of the address is stored; a decoy (address taken or unknown) has no
 * code at all, so it can never be passed. Pending details are encrypted.
 */
#[Table('otp_challenges')]
#[Hidden(['code_hash', 'destination_hash', 'payload', 'ip_hash'])]
class OtpChallenge extends Model
{
    use HasUlids;

    public const SIGNUP = 'signup';

    public const RECOVERY = 'recovery';

    public const VERIFY_CONTACT = 'verify_contact';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sends' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDecoy(): bool
    {
        return $this->code_hash === null;
    }

    public function isOpen(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->payload === null ? [] : (array) json_decode(Crypt::decryptString($this->payload), true);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function seal(array $details): string
    {
        return Crypt::encryptString((string) json_encode($details));
    }
}
