<?php

namespace App\Platform\Rules\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only history entry for a rule value.
 */
#[Table('rule_value_history')]
#[Fillable([
    'rule_value_id', 'rule_key', 'scope_type', 'scope_id', 'action',
    'old_status', 'new_status', 'snapshot', 'actor_user_id', 'reason',
])]
class RuleValueHistory extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rule history is append-only.'));
        static::deleting(fn () => throw new LogicException('Rule history is append-only.'));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
