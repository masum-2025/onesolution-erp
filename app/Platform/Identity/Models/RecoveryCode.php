<?php

namespace App\Platform\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One single-use recovery code (Phase 8-1), for when the phone is lost.
 * Only a SHA-256 of the code is kept: the person sees the codes once.
 */
#[Table('user_recovery_codes')]
#[Hidden(['code_hash'])]
class RecoveryCode extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'used_at' => 'immutable_datetime',
        ];
    }
}
