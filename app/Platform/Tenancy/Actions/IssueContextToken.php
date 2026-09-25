<?php

namespace App\Platform\Tenancy\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Context\ContextResolver;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues API tokens. The active organization (or partner) is verified here
 * once and stored on the token; later requests read it only from the token.
 */
class IssueContextToken
{
    public function __construct(
        private ContextResolver $resolver,
        private AuditLogger $audit,
    ) {}

    /**
     * A token with no context: it can only choose a context or log out.
     */
    public function forLogin(User $user): NewAccessToken
    {
        return $user->createToken('login', ['context:select'], $this->expiresAt());
    }

    public function forOrganization(User $user, string $organizationId): NewAccessToken
    {
        $context = $this->resolver->enterOrganization($user, $organizationId);

        $token = $user->createToken('organization', ['*'], $this->expiresAt());
        $token->accessToken->forceFill(['organization_id' => $organizationId])->save();

        $this->audit->record(
            action: 'auth.context_entered',
            actor: $user,
            organizationId: $organizationId,
            partnerId: $context->partner()->getKey(),
        );

        return $token;
    }

    public function forPartner(User $user, string $partnerId): NewAccessToken
    {
        $this->resolver->enterPartner($user, $partnerId);

        $token = $user->createToken('partner', ['*'], $this->expiresAt());
        $token->accessToken->forceFill(['partner_id' => $partnerId])->save();

        $this->audit->record(
            action: 'auth.partner_context_entered',
            actor: $user,
            partnerId: $partnerId,
        );

        return $token;
    }

    private function expiresAt(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('tenancy.token_ttl_minutes'));
    }
}
