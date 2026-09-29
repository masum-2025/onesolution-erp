<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Exceptions\TwoFactorException;
use App\Platform\Identity\Models\MfaReset;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\PartnerUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resetting a member's two-step sign-in when they lost their phone and
 * their recovery codes (Phase 8-1). Maker-checker: one admin asks with a
 * reason, a different admin approves within a day; the person themselves
 * never takes part. Approving removes their app, passkeys and recovery
 * codes, ends their sessions and tokens; they set it up again when they
 * next sign in (with the password, which the reset does not touch).
 *
 * One identity may work for many accounts: an organization resets only
 * people who work nowhere else (no other account, no partner staff role).
 * Anyone else uses a recovery code or asks the platform.
 */
class MfaResets
{
    public function __construct(
        private TwoFactorService $twoFactor,
        private SessionTracker $sessions,
        private AuditLogger $audit,
    ) {}

    public function request(Organization $organization, OrganizationMembership $membership, User $admin, string $reason): MfaReset
    {
        $user = $membership->user;
        if ($user->is($admin)) {
            throw TwoFactorException::resetSelf();
        }
        if (! $user->hasTwoFactor()) {
            throw TwoFactorException::resetNothing();
        }
        $this->ensureOnlyHere($user, $organization);

        $open = MfaReset::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', MfaReset::PENDING)
            ->where('expires_at', '>', now())
            ->exists();
        if ($open) {
            throw TwoFactorException::resetPending();
        }

        $reset = new MfaReset;
        $reset->forceFill([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'requested_by' => $admin->getKey(),
            'reason' => $reason,
            'status' => MfaReset::PENDING,
            'expires_at' => now()->addHours((int) config('identity.two_factor.reset_request_hours')),
        ])->save();

        $this->audit->record(action: 'identity.mfa_reset_requested', target: $reset, new: ['user' => $user->getKey()], reason: $reason, actor: $admin, organizationId: $organization->getKey());

        return $reset;
    }

    public function approve(MfaReset $reset, User $approver): void
    {
        DB::transaction(function () use ($reset, $approver) {
            $locked = MfaReset::query()->whereKey($reset->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureDecidable($locked, $approver);

            $user = $locked->user;
            $this->ensureOnlyHere($user, Organization::query()->findOrFail($locked->organization_id));

            $this->twoFactor->clearAll($user);
            $this->sessions->endAll($user);
            $user->tokens()->delete();

            $locked->forceFill(['status' => MfaReset::APPROVED, 'decided_by' => $approver->getKey(), 'decided_at' => now()])->save();

            $this->audit->record(action: 'identity.mfa_reset_approved', target: $locked, new: ['user' => $user->getKey()], actor: $approver, organizationId: $locked->organization_id);
        });
    }

    public function reject(MfaReset $reset, User $approver): void
    {
        DB::transaction(function () use ($reset, $approver) {
            $locked = MfaReset::query()->whereKey($reset->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw TwoFactorException::resetNotOpen();
            }

            $locked->forceFill(['status' => MfaReset::REJECTED, 'decided_by' => $approver->getKey(), 'decided_at' => now()])->save();

            $this->audit->record(action: 'identity.mfa_reset_rejected', target: $locked, new: ['user' => $locked->user_id], actor: $approver, organizationId: $locked->organization_id);
        });
    }

    /**
     * @return Collection<int, MfaReset>
     */
    public function open(Organization $organization): Collection
    {
        return MfaReset::query()
            ->with(['user', 'requester'])
            ->where('organization_id', $organization->getKey())
            ->where('status', MfaReset::PENDING)
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->get();
    }

    private function ensureDecidable(MfaReset $reset, User $approver): void
    {
        if (! $reset->isOpen()) {
            throw TwoFactorException::resetNotOpen();
        }
        if ($reset->requested_by === $approver->getKey()) {
            throw TwoFactorException::resetSamePerson();
        }
        if ($reset->user_id === $approver->getKey()) {
            throw TwoFactorException::resetSelf();
        }
    }

    /** Every membership of the person is inside this organization's account, and they are no partner staff. */
    private function ensureOnlyHere(User $user, Organization $organization): void
    {
        $elsewhere = OrganizationMembership::query()
            ->where('user_id', $user->getKey())
            ->whereHas('organization', fn ($query) => $query->where('root_id', '!=', $organization->root_id))
            ->exists();

        if ($elsewhere || PartnerUser::query()->where('user_id', $user->getKey())->exists()) {
            throw TwoFactorException::resetElsewhere();
        }
    }
}
