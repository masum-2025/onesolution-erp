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
            $this->notify($user, $organization, $actor, $created);

            return ['membership' => $membership, 'user' => $user, 'invited' => $created];
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

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
