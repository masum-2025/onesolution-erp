<?php

namespace App\Platform\Tenancy\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\ContextSource;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * The browser-app counterpart of IssueContextToken: verifies the membership
 * once, renews the session id and stores the context in the session.
 */
class EnterSessionContext
{
    public function __construct(
        private ContextResolver $resolver,
        private ContextSource $source,
        private AuditLogger $audit,
        private RuleResolver $rules,
    ) {}

    public function forOrganization(Request $request, User $user, string $organizationId): void
    {
        $context = $this->resolver->enterOrganization($user, $organizationId);

        $request->session()->regenerate();
        $this->source->putInSession($request, 'organization', $organizationId, $user, $this->expiresAt());

        $this->audit->record(
            action: 'auth.context_entered',
            actor: $user,
            organizationId: $organizationId,
            partnerId: $context->partner()->getKey(),
        );
    }

    public function forPartner(Request $request, User $user, string $partnerId): void
    {
        $this->resolver->enterPartner($user, $partnerId);

        $request->session()->regenerate();
        $this->source->putInSession($request, 'partner', $partnerId, $user, $this->expiresAt());

        $this->audit->record(
            action: 'auth.partner_context_entered',
            actor: $user,
            partnerId: $partnerId,
        );
    }

    /**
     * Partner staff enter a client with an approved support grant. The
     * session never outlives the grant, and the grant is checked again on
     * every request (ResolveOrganization).
     */
    public function forSupport(Request $request, User $user, string $grantId): void
    {
        $context = $this->resolver->enterSupport($user, $grantId);
        $grant = $context->supportGrant();

        $request->session()->regenerate();
        $expires = $this->expiresAt();
        $this->source->putInSession($request, 'support', $grantId, $user, $grant->expires_at->lt($expires) ? $grant->expires_at : $expires);

        $this->audit->record(
            action: 'support.session_started',
            target: $grant,
            new: ['grant' => $grant->getKey(), 'expires_at' => $grant->expires_at->toIso8601String()],
            actor: $user,
            organizationId: $grant->organization_id,
            partnerId: $grant->partner_id,
        );
    }

    /**
     * Same lifetime as a context token, resolved for the context just entered.
     */
    private function expiresAt(): CarbonInterface
    {
        return now()->addMinutes((int) $this->rules->get('tenancy.token_ttl_minutes'));
    }
}
