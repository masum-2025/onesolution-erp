<?php

namespace App\Platform\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's share of one revenue-share invoice (negative for a credit
 * note). pending until the client pays, then payable, then paid in a payout.
 */
#[Table('commissions')]
class Commission extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const PAYABLE = 'payable';

    public const PAID = 'paid';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'rate_bp' => 'integer',
            'base_minor' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
