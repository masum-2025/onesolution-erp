<?php

namespace App\Platform\Portal\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Models\OtpChallenge;
use App\Platform\Identity\Services\OtpService;
use App\Platform\Portal\Exceptions\PortalException;
use App\Platform\Portal\Models\PortalInvitation;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Using a portal invitation. The person must prove the invited email or
 * phone: an existing account must have it verified; a new account gets a
 * one-time code there first. Then they become a portal member of the client
 * (no personal workspace, no permissions) with a link to the record, active
 * at once or waiting for the client (rule client_portal.link_approval).
 */
class PortalJoin
{
    public function __construct(
        private PortalInvitations $invitations,
        private OtpService $otp,
        private AddMember $addMember,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** A signed-in person uses an invitation. */
    public function join(User $user, PortalInvitation $invitation): PortalLink
    {
        if (! $this->hasVerifiedContact($user, $invitation)) {
            throw PortalException::contactMismatch($this->invitations->masked($invitation), $invitation->channel);
        }

        return $this->link($user, $invitation);
    }

    /**
     * Someone without an account: a code goes to the invited address, and the
     * account is created when it comes back (completeSignup).
     */
    public function startSignup(PortalInvitation $invitation, string $name, string $password, ?string $ip, string $locale): OtpChallenge
    {
        if ($this->accountFor($invitation) !== null) {
            throw PortalException::accountExists();
        }

        return $this->otp->issue(
            purpose: OtpChallenge::PORTAL_JOIN,
            channel: $invitation->channel,
            destination: (string) $invitation->contact,
            partner: $invitation->organization->partner,
            details: ['name' => trim($name), 'password' => Hash::make($password), 'invitation' => $invitation->getKey()],
            ip: $ip,
            locale: $locale,
        );
    }

    /**
     * @return array{user: User, link: PortalLink}
     */
    public function completeSignup(string $challengeId, string $code): array
    {
        $challenge = $this->otp->verify($challengeId, OtpChallenge::PORTAL_JOIN, $code);
        $details = $challenge->details();

        $invitation = PortalInvitation::query()->find($details['invitation'] ?? '');
        if ($invitation === null || ! $invitation->isOpen()) {
            throw PortalException::invitationClosed();
        }

        $isPhone = $invitation->channel === 'sms';

        try {
            return DB::transaction(function () use ($details, $invitation, $isPhone) {
                $user = new User;
                $user->forceFill([
                    'name' => $details['name'],
                    'email' => $isPhone ? null : $invitation->contact,
                    'email_verified_at' => $isPhone ? null : now(),
                    'phone' => $isPhone ? $invitation->contact : null,
                    'phone_verified_at' => $isPhone ? now() : null,
                    // Already hashed when the form was sent; the cast keeps a hash as it is.
                    'password' => $details['password'],
                    'locale' => $details['locale'] ?? null,
                    // Portal people have nothing to set up: no first-run steps.
                    'onboarded_at' => now(),
                ])->save();

                $this->audit->record(action: 'identity.signed_up', target: $user, new: ['channel' => $invitation->channel, 'via' => 'portal_invitation'], actor: $user, organizationId: $invitation->organization_id, partnerId: $invitation->organization->partner_id);

                return ['user' => $user, 'link' => $this->link($user, $invitation)];
            });
        } catch (UniqueConstraintViolationException) {
            throw PortalException::accountExists();
        }
    }

    private function link(User $user, PortalInvitation $invitation): PortalLink
    {
        return DB::transaction(function () use ($user, $invitation) {
            $invitation = PortalInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            if (! $invitation->isOpen()) {
                throw PortalException::invitationClosed();
            }

            $organization = $invitation->organization;
            $membership = OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())->first();

            // Staff already see what their role allows; a portal link would mix the two.
            if ($membership !== null && $membership->membership_type !== MembershipType::Portal) {
                throw PortalException::alreadyStaff();
            }
            $membership ??= $this->addMember->handle($organization, $user, MembershipType::Portal, AccessScope::Own);

            $context = $this->contexts->forOrganization($organization);
            $max = (int) $this->rules->get('client_portal.max_links_per_person', $context);
            $open = PortalLink::query()->where('membership_id', $membership->getKey())->whereIn('status', [PortalLink::PENDING, PortalLink::ACTIVE])->count();

            $link = PortalLink::query()->where('membership_id', $membership->getKey())
                ->where('subject_type', $invitation->subject_type)->where('subject_id', $invitation->subject_id)->first();

            if ($link === null && $open >= $max) {
                throw PortalException::tooManyLinks($max);
            }

            // Both ways of joining prove the invited address, so "auto_verified" may activate at once.
            $status = $this->rules->get('client_portal.link_approval', $context) === 'auto_verified' ? PortalLink::ACTIVE : PortalLink::PENDING;

            $link ??= new PortalLink;
            if (! $link->exists || ! in_array($link->status, [PortalLink::PENDING, PortalLink::ACTIVE], true)) {
                $link->forceFill([
                    'organization_id' => $organization->getKey(),
                    'membership_id' => $membership->getKey(),
                    'user_id' => $user->getKey(),
                    'subject_type' => $invitation->subject_type,
                    'subject_id' => $invitation->subject_id,
                    'relation' => $invitation->relation,
                    'status' => $status,
                    'linked_via' => 'invitation',
                    'invitation_id' => $invitation->getKey(),
                    'decided_by' => null,
                    'decided_at' => $status === PortalLink::ACTIVE ? CarbonImmutable::now() : null,
                    'reason' => null,
                ])->save();
            }

            $invitation->forceFill(['used_at' => CarbonImmutable::now(), 'used_by' => $user->getKey()])->save();

            $this->audit->record(
                action: 'portal.joined',
                target: $link,
                new: ['subject_type' => $link->subject_type, 'subject_id' => $link->subject_id, 'status' => $link->status, 'channel' => $invitation->channel],
                actor: $user,
                organizationId: $organization->getKey(),
                partnerId: $organization->partner_id,
            );

            return $link;
        });
    }

    private function hasVerifiedContact(User $user, PortalInvitation $invitation): bool
    {
        return $invitation->channel === 'sms'
            ? $user->phone_verified_at !== null && $user->phone === $invitation->contact
            : $user->email_verified_at !== null && Str::lower((string) $user->email) === $invitation->contact;
    }

    private function accountFor(PortalInvitation $invitation): ?User
    {
        return User::query()->where($invitation->channel === 'sms' ? 'phone' : 'email', $invitation->contact)->first();
    }
}
