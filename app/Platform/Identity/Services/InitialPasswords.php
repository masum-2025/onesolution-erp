<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\PartnerUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logins an organization gives with a first password it chose (an employer
 * for employees without email, at a factory gate): the person signs in with
 * their email or phone and that password, and must choose their own before
 * any context opens (ContextResolver). The first password stops working at
 * initial_password_expires_at.
 *
 * One person = one login: someone who already has an account (same email or
 * phone) only gets the membership; their password is never touched. Only the
 * hash of the first password is ever handed in.
 */
class InitialPasswords
{
    /** What a password chosen by the person themself clears. */
    public const CLEARED = ['must_change_password' => false, 'initial_password_expires_at' => null];

    public function __construct(
        private AddMember $addMember,
        private Notifier $notifier,
        private AuditLogger $audit,
        private SessionTracker $sessions,
    ) {}

    /**
     * @return array{user: User, membership: OrganizationMembership, created: bool}
     */
    public function give(
        Organization $unit,
        string $name,
        ?string $email,
        ?string $phone,
        ?string $country,
        string $passwordHash,
        CarbonImmutable $expiresAt,
        User $actor,
    ): array {
        $email = $email === null || trim($email) === '' ? null : mb_strtolower(trim($email));
        $e164 = $phone === null || trim($phone) === '' ? null : PhoneNumber::normalize($phone, $country);
        if ($email === null && $e164 === null) {
            throw ValidationException::withMessages(['login' => __('identity.errors.initial_login_needs_contact')]);
        }

        return DB::transaction(function () use ($unit, $name, $email, $e164, $passwordHash, $expiresAt, $actor) {
            $user = ($email === null ? null : User::query()->where('email', $email)->first())
                ?? ($e164 === null ? null : User::query()->where('phone', $e164)->first());
            $created = $user === null;

            if ($created) {
                $user = User::query()->forceCreate([
                    'name' => trim($name),
                    'email' => $email,
                    // A phone someone else already uses stays off this account.
                    'phone' => $e164 !== null && ! User::query()->where('phone', $e164)->exists() ? $e164 : null,
                    'password' => $passwordHash,
                    'must_change_password' => true,
                    'initial_password_expires_at' => $expiresAt,
                ]);
                // The employer vouches for the phone it entered, so the person can sign in with it.
                if ($user->phone !== null) {
                    $user->forceFill(['phone_verified_at' => now()])->save();
                }
            }

            $membership = OrganizationMembership::query()->where('organization_id', $unit->getKey())->where('user_id', $user->getKey())->first()
                ?? $this->addMember->handle($unit, $user, MembershipType::Staff, AccessScope::Own, $actor);

            $this->audit->record(
                action: 'identity.initial_login_given',
                target: $user,
                new: [
                    'created' => $created,
                    'expires_at' => $created ? $expiresAt->toIso8601String() : null,
                    'phone_verified_by' => $created && $user->phone !== null ? 'employer' : null,
                ],
                actor: $actor,
                organizationId: $unit->getKey(),
                partnerId: $unit->partner_id,
            );

            // Someone with an account of their own hears they were given access, as with an invitation.
            if (! $created && $membership->wasRecentlyCreated) {
                $this->notifier->notify('members.added', [$user], ['organization' => (string) $unit->displayName(), 'inviter' => $actor->name], $unit->partner, $unit);
            }

            return ['user' => $user, 'membership' => $membership, 'created' => $created];
        });
    }

    /**
     * A new first password for a login that has not chosen its own yet, and
     * belongs to $company alone (no membership elsewhere, no partner console):
     * one organization never sets the password of someone else's login.
     */
    public function reset(User $user, Organization $company, string $passwordHash, CarbonImmutable $expiresAt, User $actor): User
    {
        if (! $this->resettableBy($user, $company)) {
            throw ValidationException::withMessages(['login' => __('identity.errors.initial_password_not_resettable')]);
        }

        return DB::transaction(function () use ($user, $company, $passwordHash, $expiresAt, $actor) {
            $user->forceFill(['password' => $passwordHash, 'must_change_password' => true, 'initial_password_expires_at' => $expiresAt])->save();
            $this->sessions->endAll($user);
            $user->tokens()->delete();

            $this->audit->record(
                action: 'identity.initial_password_reset',
                target: $user,
                new: ['expires_at' => $expiresAt->toIso8601String()],
                actor: $actor,
                organizationId: $company->getKey(),
                partnerId: $company->partner_id,
            );

            return $user;
        });
    }

    public function resettableBy(User $user, Organization $company): bool
    {
        if (! $user->must_change_password) {
            return false;
        }

        $inside = Organization::query()->subtreeOf($company)->pluck('id')->map(fn ($id) => (string) $id)->all();

        return ! $user->memberships()->whereNotIn('organization_id', $inside)->exists()
            && ! PartnerUser::query()->where('user_id', $user->getKey())->exists();
    }

    /** none | pending (first password still to be replaced) | expired | active */
    public static function state(?User $user): string
    {
        return match (true) {
            $user === null => 'none',
            ! $user->must_change_password => 'active',
            $user->initial_password_expires_at !== null && ! $user->initial_password_expires_at->isFuture() => 'expired',
            default => 'pending',
        };
    }
}
