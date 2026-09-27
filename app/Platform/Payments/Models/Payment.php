<?php

namespace App\Platform\Payments\Models;

use App\Platform\Billing\Models\Invoice;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay online: a plan at checkout or an open invoice (paying
 * us), or a customer's collection (paying a client into its own merchant
 * account, Phase 6). The
 * amount is fixed by the server when it starts; the status only moves
 * forward (pending → succeeded | failed | cancelled | expired | review, and
 * a late confirmation may still turn failed/expired into succeeded because
 * the money was taken). Nothing is mass assignable.
 *
 * Not tenant-scoped on purpose: gateway messages arrive without a signed-in
 * person. Every read by a person filters by the organization in the context.
 */
#[Table('payments')]
class Payment extends Model
{
    use HasUlids;

    public const CHECKOUT = 'checkout';

    public const INVOICE = 'invoice';

    // A client's customer paying it for a module's record (Phase 6).
    public const COLLECTION = 'collection';

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    // Money taken but not applied (amount differs, gateway risk flag): a person checks it.
    public const REVIEW = 'review';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_minor' => 'integer',
            'amount_minor' => 'integer',
            'refund_due' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<MerchantAccount, $this>
     */
    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    /** A late confirmation can still apply: the gateway took the money. */
    public function canSucceed(): bool
    {
        return in_array($this->status, [self::PENDING, self::FAILED, self::CANCELLED, self::EXPIRED], true);
    }
}
