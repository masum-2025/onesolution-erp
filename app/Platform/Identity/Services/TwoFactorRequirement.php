<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Rules\ResolvedRule;
use App\Platform\Rules\RuleContext;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Contracts\SignInRequirements;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use Carbon\CarbonImmutable;

/**
 * Whether the context just entered requires two-step sign-in (Phase 8-1):
 *
 *  - staff of an organization: identity.mfa_required (may differ per role);
 *  - portal people: identity.mfa_required_portal;
 *  - partner console and partner support access: identity.mfa_required_partner_staff.
 *
 * Someone without a second step gets identity.mfa_grace_days from the first
 * time any context required it (users.mfa_required_since), then that
 * context refuses them (two_factor_required) until they set one up. Their
 * own account and security pages stay open, so they always can.
 *
 * Scoped per request: /api/me reads the due date to show a reminder.
 */
class TwoFactorRequirement implements SignInRequirements
{
    private ?CarbonImmutable $dueAt = null;

    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function check(User $user, CurrentContext $context): void
    {
        $this->dueAt = null;

        if ($user->hasTwoFactor() || ! $this->resolve($context)?->value) {
            return;
        }

        if ($user->mfa_required_since === null) {
            $user->forceFill(['mfa_required_since' => now()])->save();
        }

        $due = $user->mfa_required_since->addDays((int) $this->rules->get('identity.mfa_grace_days', $this->ruleContext($context)));
        if (! $due->isFuture()) {
            throw OrganizationAccessDenied::twoFactorRequired();
        }

        $this->dueAt = $due;
    }

    /** Set when the current context requires two-step sign-in and the person still has time to set it up. */
    public function setupDueAt(): ?CarbonImmutable
    {
        return $this->dueAt;
    }

    /**
     * For the security page: required here or not, where that comes from, and until when to set it up.
     *
     * @return array<string, mixed>|null
     */
    public function describe(User $user, CurrentContext $context): ?array
    {
        $resolved = $this->resolve($context);
        if ($resolved === null) {
            return null;
        }

        $required = (bool) $resolved->value;
        $due = null;
        if ($required && ! $user->hasTwoFactor()) {
            $grace = (int) $this->rules->get('identity.mfa_grace_days', $this->ruleContext($context));
            $due = ($user->mfa_required_since ?? CarbonImmutable::now())->addDays($grace)->toIso8601String();
        }

        return [
            'required' => $required,
            'due_at' => $due,
            'source' => $resolved->sourceLevel === null ? null : ['level' => $resolved->sourceLevel, 'name' => $resolved->sourceName],
            'locked_by' => $resolved->isLockedByAncestor() ? ['level' => $resolved->lockedByLevel, 'name' => $resolved->lockedByName] : null,
        ];
    }

    /** The rule that applies in this context, with where its value comes from; null without a context. */
    public function resolve(CurrentContext $context): ?ResolvedRule
    {
        if (! $context->hasOrganization() && ! $context->hasPartnerConsole()) {
            return null;
        }

        return $this->rules->resolve($this->ruleKey($context), $this->ruleContext($context));
    }

    private function ruleKey(CurrentContext $context): string
    {
        return match (true) {
            $context->hasPartnerConsole(), $context->isSupport() => 'identity.mfa_required_partner_staff',
            $context->membership()->membership_type === MembershipType::Portal => 'identity.mfa_required_portal',
            default => 'identity.mfa_required',
        };
    }

    private function ruleContext(CurrentContext $context): ?RuleContext
    {
        // Support access is partner staff inside a client: the partner's rule decides.
        return $context->isSupport() ? $this->contexts->forPartner($context->partner()) : null;
    }
}
