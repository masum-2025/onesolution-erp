<?php

namespace App\Platform\Transfers\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time code a partner gives a client so the client can move to it.
 * Only the SHA-256 of the code is stored; the partner sees it once.
 */
#[Table('transfer_codes')]
#[Hidden(['code_hash'])]
class TransferCode extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->used_at !== null => 'used',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
