<?php

namespace App\Platform\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The person's authenticator app (Phase 8-1). The secret is encrypted at
 * rest and never serialized; last_used_step stops a code being used twice.
 * Unconfirmed until the person enters a first code from the app.
 */
#[Table('user_totp')]
#[Hidden(['secret'])]
class TotpSecret extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'confirmed_at' => 'immutable_datetime',
            'last_used_step' => 'integer',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
