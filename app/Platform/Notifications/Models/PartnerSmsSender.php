<?php

namespace App\Platform\Notifications\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A partner's SMS sender ID. Used only once approved.
 */
#[Table('partner_sms_senders')]
class PartnerSmsSender extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
