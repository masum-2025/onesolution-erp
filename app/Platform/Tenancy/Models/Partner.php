<?php

namespace App\Platform\Tenancy\Models;

use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\PartnerStatus;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Top tenant owner: a white-label reseller, or the "house" partner that owns
 * direct clients. Every organization belongs to exactly one partner.
 *
 * `is_house` and `parent_partner_id` are platform-level decisions and are
 * never mass assignable.
 */
#[Fillable(['name', 'slug', 'status', 'billing_mode', 'settings'])]
#[UseFactory(PartnerFactory::class)]
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => PartnerStatus::class,
            'billing_mode' => BillingMode::class,
            'is_house' => 'boolean',
            'settings' => 'array',
            // Set by the platform (partners:suspend); starts the clients' grace period.
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    /**
     * @return HasMany<PartnerUser, $this>
     */
    public function partnerUsers(): HasMany
    {
        return $this->hasMany(PartnerUser::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function parentPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'parent_partner_id');
    }

    public function isActive(): bool
    {
        return $this->status === PartnerStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === PartnerStatus::Suspended;
    }
}
