<?php

namespace App\Platform\Modules\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\Exceptions\ModuleException;
use App\Platform\Modules\Models\ModuleConsent;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Tenancy\Models\Organization;

/**
 * Admin consent for modules with requires_consent (AI). Without an active
 * consent at the organization or an ancestor, the module resolves disabled,
 * so revoking consent switches it off immediately.
 */
class ModuleConsentService
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleToggleService $toggles,
        private AuditLogger $audit,
    ) {}

    public function grant(Organization $organization, string $key, string $termsVersion, ?string $reason, User $actor): ModuleConsent
    {
        $module = $this->registry->get($key);

        if (! $module->requiresConsent) {
            throw ModuleException::consentNotApplicable($module->label());
        }

        return $this->toggles->withTransitions($organization, $actor, (string) $reason, function () use ($organization, $key, $termsVersion, $reason, $actor) {
            // One active consent per level: a new grant replaces the previous one.
            $this->revokeActive($organization, $key, $actor);

            $consent = ModuleConsent::create([
                'organization_id' => $organization->getKey(),
                'module_key' => $key,
                'terms_version' => $termsVersion,
                'granted_by' => $actor->getKey(),
                'granted_at' => now(),
                'reason' => $reason,
            ]);

            $this->audit->record(
                action: 'module.consent_granted',
                target: $consent,
                new: ['module' => $key, 'terms_version' => $termsVersion],
                reason: $reason,
                actor: $actor,
                organizationId: $organization->getKey(),
                partnerId: $organization->partner_id,
            );

            return $consent;
        });
    }

    public function revoke(Organization $organization, string $key, string $reason, User $actor): void
    {
        $this->registry->get($key);

        $this->toggles->withTransitions($organization, $actor, $reason, function () use ($organization, $key, $reason, $actor) {
            $revoked = $this->revokeActive($organization, $key, $actor);

            if ($revoked === null) {
                throw ModuleException::consentNotFound();
            }

            $this->audit->record(
                action: 'module.consent_revoked',
                target: $revoked,
                old: ['terms_version' => $revoked->terms_version],
                reason: $reason,
                actor: $actor,
                organizationId: $organization->getKey(),
                partnerId: $organization->partner_id,
            );
        });
    }

    private function revokeActive(Organization $organization, string $key, User $actor): ?ModuleConsent
    {
        $active = ModuleConsent::query()
            ->active()
            ->where('organization_id', $organization->getKey())
            ->where('module_key', $key)
            ->first();

        $active?->forceFill(['revoked_by' => $actor->getKey(), 'revoked_at' => now()])->save();

        return $active;
    }
}
