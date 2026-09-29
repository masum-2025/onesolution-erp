<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\PartnerUser;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * One identity (one login). Access to data always goes through a membership:
 * an organization membership (client area) or a partner membership (console).
 * People sign in with their email, or their verified phone (E.164); a
 * self-serve person may have only one of the two (Phase 5C).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'recovered_at' => 'datetime',
            'onboarded_at' => 'datetime',
            // "Delete my account" (Phase 5C-3).
            'deletion_requested_at' => 'immutable_datetime',
            'deletion_due_at' => 'immutable_datetime',
            'erased_at' => 'immutable_datetime',
            // Two-step sign-in (Phase 8-1).
            'mfa_enabled_at' => 'immutable_datetime',
            'mfa_required_since' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /** Has a second step (authenticator app or passkey), so signing in needs it. */
    public function hasTwoFactor(): bool
    {
        return $this->mfa_enabled_at !== null;
    }

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * @return HasMany<PartnerUser, $this>
     */
    public function partnerMemberships(): HasMany
    {
        return $this->hasMany(PartnerUser::class);
    }
}
