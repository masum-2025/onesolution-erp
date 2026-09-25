<?php

namespace App\Platform\Modules\Listeners;

use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * api_integration turned off: revoke integration tokens of every organization
 * in the subtree where the module is now disabled. Runs synchronously so no
 * token keeps working after the response. Session tokens are not touched.
 */
class RevokeIntegrationTokens
{
    public function __construct(
        private ModuleResolver $resolver,
        private AuditLogger $audit,
    ) {}

    public function handle(ModuleDisabled $event): void
    {
        if ($event->moduleKey !== 'api_integration') {
            return;
        }

        $prefix = (string) config('platform_modules.integration_token_prefix');

        Organization::query()->subtreeOf($event->organization)->each(function (Organization $organization) use ($prefix, $event) {
            if ($this->resolver->isEnabled('api_integration', $organization)) {
                return;
            }

            $revoked = PersonalAccessToken::query()
                ->where('organization_id', $organization->getKey())
                ->where('name', 'like', $prefix.'%')
                ->delete();

            if ($revoked > 0) {
                $this->audit->record(
                    action: 'module.integration_tokens_revoked',
                    target: $organization,
                    new: ['revoked' => $revoked],
                    reason: $event->reason,
                    actor: $event->actor,
                    organizationId: $organization->getKey(),
                    partnerId: $organization->partner_id,
                );
            }
        });
    }
}
