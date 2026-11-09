<?php

namespace App\Platform\Meta\Models;

use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's own Meta app (SC-1): its clients' Facebook pages connect
 * through it, so the sign-in shows the partner's app name. The secret is
 * encrypted at rest, hidden from serialization and read only by the Graph
 * client. A change waits (pending_*) for a second partner owner's approval
 * or the single-approver wait. Nothing is mass assignable.
 */
#[Table('meta_apps')]
class MetaApp extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const DISABLED = 'disabled';

    protected $guarded = ['*'];

    protected $hidden = ['app_secret', 'pending_app_secret'];

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'pending_app_secret' => 'encrypted',
            'pending_at' => 'immutable_datetime',
            'activates_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->app_id !== null && $this->app_secret !== null;
    }

    public function hasPendingChange(): bool
    {
        return $this->pending_app_id !== null;
    }
}
