<?php

namespace App\Platform\Payments\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A client's own account at a payment gateway (Phase 6): its customers'
 * payments go there, not to the platform. Credentials are encrypted at rest,
 * hidden from serialization, and only ever read by the gateway driver.
 *
 * A change waits (pending_*) for a second person's approval, or for the
 * single-approver wait to end; the approved credentials keep working until
 * then. Nothing is mass assignable.
 *
 * Gateway callbacks load it without a context (withoutGlobalScope), by the
 * payment they concern.
 */
#[Table('merchant_accounts')]
class MerchantAccount extends Model
{
    use BelongsToOrganization, HasUlids;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const DISABLED = 'disabled';

    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    protected $guarded = ['*'];

    protected $hidden = ['credentials', 'pending_credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'pending_credentials' => 'encrypted:array',
            'approved_at' => 'immutable_datetime',
            'pending_at' => 'immutable_datetime',
            'activates_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function hasPendingChange(): bool
    {
        return $this->pending_credentials !== null;
    }

    /** Takes customers' payments now: active, with approved credentials. */
    public function canCollect(): bool
    {
        return $this->isActive() && $this->credentials !== null;
    }
}
