<?php

namespace App\Platform\Audit;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit entry. Rows are never updated or deleted by the app.
 * Phase 9 adds reporting, retention and external shipping on top.
 */
#[Fillable([
    'partner_id', 'organization_id', 'actor_user_id', 'action', 'target_type',
    'target_id', 'old_values', 'new_values', 'reason', 'ip_address', 'user_agent',
])]
class AuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit log entries are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }
}
