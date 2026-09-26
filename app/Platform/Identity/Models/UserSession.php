<?php

namespace App\Platform\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser or device where the person is signed in. Kept apart from the
 * session store so the list works with any store; the session id itself is
 * never stored, only its hash.
 */
#[Table('user_sessions')]
#[Hidden(['session_hash'])]
class UserSession extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashOf(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
