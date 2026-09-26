<?php

namespace App\Platform\Notifications\Models;

use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's own sending domain. Mail goes out from it only while every
 * DNS check passes (status active). The DKIM private key is encrypted at
 * rest and hidden from every serialization.
 */
#[Table('partner_mail_domains')]
#[Fillable(['local_part', 'from_name', 'reply_to'])]
#[Hidden(['dkim_private_key', 'verification_token'])]
class PartnerMailDomain extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const CHECKS = ['ownership', 'spf', 'dkim', 'dmarc'];

    protected function casts(): array
    {
        return [
            'dkim_private_key' => 'encrypted',
            'checks' => 'array',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function fromAddress(): string
    {
        return "{$this->local_part}@{$this->domain}";
    }

    /**
     * The DNS records the partner publishes, with the value each must hold.
     *
     * @return array<string, array{type: string, name: string, value: string}>
     */
    public function records(): array
    {
        return [
            'ownership' => ['type' => 'TXT', 'name' => "_onesolution-verify.{$this->domain}", 'value' => "onesolution-verify={$this->verification_token}"],
            'spf' => ['type' => 'TXT', 'name' => $this->domain, 'value' => 'v=spf1 include:'.config('notifications.mail.spf_include').' ~all'],
            'dkim' => ['type' => 'TXT', 'name' => "{$this->dkim_selector}._domainkey.{$this->domain}", 'value' => "v=DKIM1; k=rsa; p={$this->dkim_public_key}"],
            'dmarc' => ['type' => 'TXT', 'name' => "_dmarc.{$this->domain}", 'value' => "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$this->domain}"],
        ];
    }
}
