<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\Models\TrialGrant;
use App\Platform\Billing\SelfServe\SelfServeAccount;
use App\Platform\Billing\Services\CreditNotes;
use App\Platform\DataExport\Services\DataExportService;
use App\Platform\Identity\Events\WorkspaceErased;
use App\Platform\Identity\Exceptions\IdentityException;
use App\Platform\Identity\Models\OtpChallenge;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\LocalDate;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\PartnerUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Delete my account" (Phase 5C-3). The person asks (password and a typed
 * confirmation), a grace period runs (rule privacy.account_deletion_grace_days)
 * in which they can still sign in and cancel, then their personal data is
 * erased:
 *
 * - workspaces that are theirs alone (self-serve, no other member) are closed:
 *   unpaid invoices cancelled, archived, name removed; modules delete their
 *   business data on WorkspaceErased;
 * - memberships elsewhere end; the records a B2B client owns stay with it;
 * - the person's row is anonymized (name, email, phone, password), and their
 *   sessions, tokens and codes are deleted. The row itself stays so audit
 *   trails and client records keep pointing at "a deleted person".
 *
 * Kept on purpose: issued invoices (tax law), the audit log (security), and
 * trial grants (hashes only, against trial abuse).
 *
 * Nothing that only this person holds is left without an owner: being the
 * only owner of an organization with other members, or of a partner account,
 * blocks the request until handed over.
 */
class AccountDeletion
{
    public function __construct(
        private AccountService $account,
        private SignupGate $gate,
        private Notifier $notifier,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private SubscriptionService $subscriptions,
        private SelfServeAccount $selfServe,
        private CreditNotes $creditNotes,
        private DataExportService $exports,
    ) {}

    /**
     * What stops the account from being deleted now.
     *
     * @return list<array{code: string, name: string}>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        $partnerOwnerships = PartnerUser::query()->with('partner')
            ->where('user_id', $user->getKey())
            ->where('role', PartnerUserRole::Owner)
            ->where('status', MembershipStatus::Active)
            ->get();
        foreach ($partnerOwnerships as $ownership) {
            $others = PartnerUser::query()
                ->where('partner_id', $ownership->partner_id)
                ->where('role', PartnerUserRole::Owner)
                ->where('status', MembershipStatus::Active)
                ->where('user_id', '!=', $user->getKey())
                ->exists();
            if (! $others) {
                $blockers[] = ['code' => 'partner_owner', 'name' => $ownership->partner->name];
            }
        }

        foreach ($this->ownerships($user) as $membership) {
            $organization = $membership->organization;
            $otherMembers = $this->otherActiveMembers($organization->root_id ?? $organization->getKey(), $user)->isNotEmpty();
            $otherOwner = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('membership_type', MembershipType::Owner)
                ->where('status', MembershipStatus::Active)
                ->where('user_id', '!=', $user->getKey())
                ->exists();

            if ($otherMembers && ! $otherOwner) {
                $blockers[] = ['code' => 'only_owner', 'name' => $organization->displayName()];
            }
        }

        return $blockers;
    }

    public function request(User $user, string $password): User
    {
        $this->account->assertPassword($user, $password);

        $blockers = $this->blockers($user);
        if ($blockers !== []) {
            throw IdentityException::deletionBlocked($this->describe($blockers));
        }

        if ($user->deletion_due_at !== null) {
            return $user;
        }

        $due = CarbonImmutable::now()->addDays($this->graceDays($user));
        $user->forceFill(['deletion_requested_at' => CarbonImmutable::now(), 'deletion_due_at' => $due])->save();

        $this->audit->record(action: 'identity.deletion_requested', target: $user, new: ['due_at' => $due->toIso8601String()], actor: $user);
        $this->notifier->notify('identity.deletion_requested', [$user], fn (string $locale) => [
            'date' => LocalDate::format($due, $locale),
        ], $this->gate->addressPartner(), locale: $user->locale, allChannels: true);

        return $user;
    }

    public function cancel(User $user): User
    {
        if ($user->deletion_due_at === null) {
            throw IdentityException::noDeletionPending();
        }

        $user->forceFill(['deletion_requested_at' => null, 'deletion_due_at' => null])->save();

        $this->audit->record(action: 'identity.deletion_cancelled', target: $user, actor: $user);
        $this->notifier->notify('identity.deletion_cancelled', [$user], [], $this->gate->addressPartner(), locale: $user->locale, allChannels: true);

        return $user;
    }

    /**
     * Erases every account whose grace period is over. Safe to repeat. An
     * account that became blocked meanwhile (e.g. new members joined) waits.
     *
     * @return array{erased: int, waiting: int}
     */
    public function eraseDue(CarbonImmutable $now): array
    {
        $counts = ['erased' => 0, 'waiting' => 0];

        $due = User::query()->whereNull('erased_at')->whereNotNull('deletion_due_at')->where('deletion_due_at', '<=', $now)->lazyById();

        foreach ($due as $user) {
            if ($this->blockers($user) !== []) {
                $counts['waiting']++;

                continue;
            }

            $this->erase($user);
            $counts['erased']++;
        }

        return $counts;
    }

    public function erase(User $user): void
    {
        DB::transaction(function () use ($user) {
            $closed = 0;
            foreach ($this->ownedAlone($user) as $root) {
                $this->closeWorkspace($root, $user);
                $closed++;
            }

            // Memberships elsewhere end; the organizations keep their records.
            OrganizationMembership::query()->where('user_id', $user->getKey())->update(['status' => MembershipStatus::Suspended]);
            PartnerUser::query()->where('user_id', $user->getKey())->update(['status' => MembershipStatus::Suspended]);

            UserSession::query()->where('user_id', $user->getKey())->delete();
            OtpChallenge::query()->where('user_id', $user->getKey())->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            $user->tokens()->delete();
            TrialGrant::query()->where('user_id', $user->getKey())->update(['user_id' => null]);

            $user->forceFill([
                'name' => __('identity.erased_name', [], 'en'),
                'email' => null,
                'email_verified_at' => null,
                'phone' => null,
                'phone_verified_at' => null,
                'password' => Str::random(64),
                'remember_token' => null,
                'locale' => null,
                'marketing_consent_at' => null,
                'deletion_due_at' => null,
                'erased_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(
                action: 'identity.account_erased',
                target: $user,
                new: ['workspaces_closed' => $closed],
                actor: null,
            );
        });
    }

    /**
     * Self-serve accounts where this person is the only member: theirs alone.
     *
     * @return Collection<int, Organization>
     */
    public function ownedAlone(User $user): Collection
    {
        return $this->ownerships($user)
            ->map(fn (OrganizationMembership $membership) => $membership->organization)
            ->filter(fn (Organization $organization) => $organization->isRoot()
                && $organization->status !== OrganizationStatus::Archived
                && $this->subscriptions->for($organization)->self_serve
                && $this->otherActiveMembers($organization->getKey(), $user)->isEmpty())
            ->values();
    }

    public function graceDays(User $user): int
    {
        $own = $this->ownedAlone($user)->first();
        $partner = $this->gate->addressPartner();

        $context = match (true) {
            $own !== null => $this->contexts->forOrganization($own),
            $partner !== null => $this->contexts->forPartner($partner),
            default => $this->contexts->platform(),
        };

        return (int) $this->rules->get('privacy.account_deletion_grace_days', $context);
    }

    /**
     * @param  list<array{code: string, name: string}>  $blockers
     * @return list<array{code: string, name: string, message: string}>
     */
    public function describe(array $blockers): array
    {
        return array_map(fn (array $blocker) => [
            ...$blocker,
            'message' => __("identity.blockers.{$blocker['code']}", ['name' => $blocker['name']]),
        ], $blockers);
    }

    private function closeWorkspace(Organization $root, User $user): void
    {
        $subscription = Subscription::query()->where('organization_id', $root->getKey())->lockForUpdate()->firstOrFail();

        foreach ($this->selfServe->openInvoices($root) as $invoice) {
            $this->creditNotes->issue($invoice, $this->creditNotes->remaining($invoice), 'Cancelled: the account holder deleted their account.');
        }

        $subscription->forceFill([
            'status' => Subscription::CANCELLED,
            'cancel_at_period_end' => false,
            'trial_ends_at' => null,
            'trial_plan_key' => null,
        ])->save();

        try {
            $this->exports->discardFor($root);
        } catch (Throwable) {
            // Export files also expire on their own (exports:prune); never block the erasure.
        }

        $names = [];
        foreach ((array) config('tenancy.supported_locales') as $locale) {
            $names[$locale] = __('identity.erased_workspace', [], $locale);
        }
        $root->forceFill(['status' => OrganizationStatus::Archived, 'name' => $names])->save();

        $this->audit->record(
            action: 'organization.erased',
            target: $root,
            new: ['reason' => 'account_deleted'],
            organizationId: $root->getKey(),
            partnerId: $root->partner_id,
        );

        WorkspaceErased::dispatch($root);
    }

    /**
     * @return Collection<int, OrganizationMembership>
     */
    private function ownerships(User $user): Collection
    {
        return OrganizationMembership::query()->with('organization')
            ->where('user_id', $user->getKey())
            ->where('membership_type', MembershipType::Owner)
            ->where('status', MembershipStatus::Active)
            ->get();
    }

    /**
     * @return Collection<int, OrganizationMembership>
     */
    private function otherActiveMembers(string $rootId, User $user): Collection
    {
        return OrganizationMembership::query()
            ->whereIn('organization_id', Organization::query()->where('root_id', $rootId)->orWhere('id', $rootId)->select('id'))
            ->where('status', MembershipStatus::Active)
            ->where('user_id', '!=', $user->getKey())
            ->get();
    }
}
