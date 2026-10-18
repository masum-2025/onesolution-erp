<?php

namespace App\Platform\Invitations\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Invitations\Models\Invitation;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds people to an organization by email. Someone new gets an account
 * without a usable password and a one-time link to set one (valid for
 * INVITE_HOURS); someone who already has an account just gets access and an
 * email saying so. One person = one login, whatever the organization.
 */
class InvitationService
{
    public const INVITE_HOURS = 72;

    public function __construct(private AddMember $addMember, private Notifier $notifier, private AuditLogger $audit) {}

    /**
     * @return array{user: User, created: bool}
     */
    public function userFor(string $email, string $name): array
    {
        $email = mb_strtolower(trim($email));
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            return ['user' => $existing, 'created' => false];
        }

        // No one knows this password; the invitation link sets the real one.
        $user = User::query()->forceCreate(['name' => trim($name), 'email' => $email, 'password' => Str::random(64)]);

        return ['user' => $user, 'created' => true];
    }

    /**
     * @return array{membership: OrganizationMembership, user: User, invited: bool}
     */
    public function invite(Organization $organization, string $email, string $name, MembershipType $type, AccessScope $scope, User $actor): array
    {
        return DB::transaction(function () use ($organization, $email, $name, $type, $scope, $actor) {
            ['user' => $user, 'created' => $created] = $this->userFor($email, $name);
            $membership = $this->addMember->handle($organization, $user, $type, $scope, $actor);
            // Someone invited elsewhere who never set a password needs a link too, not "you were added".
            $needsLink = $created || Invitation::query()->where('user_id', $user->getKey())->whereNull('accepted_at')->exists();
            $this->notify($user, $organization, $actor, $needsLink);

            return ['membership' => $membership, 'user' => $user, 'invited' => $needsLink];
        });
    }

    /**
     * Tell someone they were given access: a link to set a password for a new
     * account, or a link to sign in for an existing one.
     */
    public function notify(User $user, Organization $organization, User $actor, bool $newAccount): void
    {
        $values = ['organization' => (string) $organization->displayName(), 'inviter' => $actor->name];

        if (! $newAccount) {
            $this->notifier->notify('members.added', [$user], $values, $organization->partner, $organization);

            return;
        }

        $token = Str::random(48);
        $invitation = new Invitation;
        $invitation->forceFill([
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'token_hash' => self::hash($token),
            'expires_at' => now()->addHours(self::INVITE_HOURS),
            'invited_by' => $actor->getKey(),
        ])->save();

        $this->audit->record(
            action: 'membership.invited',
            target: $invitation,
            new: ['user_id' => $user->getKey(), 'expires_at' => $invitation->expires_at->toIso8601String()],
            actor: $actor,
            organizationId: $organization->getKey(),
            partnerId: $organization->partner_id,
        );

        $this->notifier->notify('members.invited', [$user], $values, $organization->partner, $organization, "/invite/{$token}");
    }

    public function find(string $token): ?Invitation
    {
        return strlen($token) !== 48 ? null : Invitation::query()->where('token_hash', self::hash($token))->first();
    }

    /**
     * The person sets their password; the link is used up.
     */
    public function accept(Invitation $invitation, string $password): User
    {
        return DB::transaction(function () use ($invitation, $password) {
            $invitation = Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            $user = $invitation->user;

            $user->forceFill(['password' => $password, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            $invitation->forceFill(['accepted_at' => now()])->save();
            // Other open links for the same person stop working too.
            Invitation::query()->where('user_id', $user->getKey())->whereNull('accepted_at')->update(['accepted_at' => now(), 'updated_at' => now()]);

            $this->audit->record(
                action: 'membership.invitation_accepted',
                target: $invitation,
                actor: $user,
                organizationId: $invitation->organization_id,
                partnerId: $invitation->organization?->partner_id,
            );

            return $user;
        });
    }

    /**
     * People of an organization still to set their password (their link not
     * used yet): when their latest link runs out, by user id.
     *
     * @param  list<string>  $userIds
     * @return array<string, array{expires_at: string, expired: bool}>
     */
    public function pending(Organization $organization, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Invitation::query()->where('organization_id', $organization->getKey())->whereIn('user_id', $userIds)->whereNull('accepted_at')
            ->orderBy('expires_at')->get(['user_id', 'expires_at'])
            ->mapWithKeys(fn (Invitation $invitation) => [$invitation->user_id => [
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'expired' => $invitation->expires_at->isPast(),
            ]])->all();
    }

    /**
     * A new link for someone who has not set their password yet (the old
     * one may have run out); the old links stop working. False when they
     * already have (nothing to send: they sign in).
     */
    public function resend(Organization $organization, User $user, User $actor): bool
    {
        return DB::transaction(function () use ($organization, $user, $actor) {
            $open = Invitation::query()->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())
                ->whereNull('accepted_at')->lockForUpdate()->get();
            if ($open->isEmpty()) {
                return false;
            }
            Invitation::query()->whereKey($open->modelKeys())->update(['expires_at' => now(), 'updated_at' => now()]);
            $this->notify($user, $organization, $actor, newAccount: true);

            return true;
        });
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
