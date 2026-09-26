<?php

namespace App\Platform\Partners\Models;

use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom host (erp.partner.com, or school.partner.com for one client).
 * Only an active (DNS-verified) domain resolves; written by DomainService.
 */
#[Table('partner_domains')]
#[Fillable(['partner_id', 'organization_id', 'host', 'status', 'verification_token', 'verified_at', 'last_checked_at', 'created_by'])]
class PartnerDomain extends Model
{
    use HasUlids;

    /** The TXT record name that proves ownership: "{prefix}.{host}". */
    public const TXT_PREFIX = '_onesolution-verify';

    protected $hidden = ['verification_token'];

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
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

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function txtName(): string
    {
        return self::TXT_PREFIX.'.'.$this->host;
    }

    public function txtValue(): string
    {
        return 'onesolution-verify='.$this->verification_token;
    }

    public function isActive(): bool
    {
        return $this->status === DomainStatus::Active;
    }
}
