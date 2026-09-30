<?php

namespace App\Platform\Monitoring\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A raised security alert (Phase 9-2). Written only by AlertService and the
 * operators' console commands.
 */
#[Table('security_alerts')]
#[Fillable([
    'kind', 'severity', 'fingerprint', 'partner_id', 'organization_id', 'user_id', 'count', 'details',
    'first_seen_at', 'last_seen_at', 'notified_at', 'acknowledged_at', 'acknowledged_by', 'note',
])]
class SecurityAlert extends Model
{
    use HasUlids;

    public const SEVERITIES = ['low' => 1, 'medium' => 2, 'high' => 3];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'notified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->acknowledged_at === null;
    }

    /** Whether this alert is at least as severe as $minimum. */
    public function atLeast(string $minimum): bool
    {
        return (self::SEVERITIES[$this->severity] ?? 0) >= (self::SEVERITIES[$minimum] ?? PHP_INT_MAX);
    }
}
