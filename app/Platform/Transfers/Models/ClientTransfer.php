<?php

namespace App\Platform\Transfers\Models;

use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client (top organization and everything under it) moving to another
 * partner, with the client's consent and the new partner's acceptance.
 */
#[Table('client_transfers')]
class ClientTransfer extends Model
{
    use HasUlids;

    public const AWAITING_PARTNER = 'awaiting_partner';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'by_platform' => 'boolean',
            'consented_at' => 'datetime',
            'decided_at' => 'datetime',
            'completed_at' => 'datetime',
            'summary' => 'array',
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
     * @return BelongsTo<Partner, $this>
     */
    public function fromPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'from_partner_id');
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function toPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'to_partner_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::AWAITING_PARTNER;
    }
}
