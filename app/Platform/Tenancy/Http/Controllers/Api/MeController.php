<?php

namespace App\Platform\Tenancy\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Tenancy\Actions\ListAvailableContexts;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\ContextSource;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Everything the app needs to start: who is signed in, the active context
 * (re-verified now), where else they may go, what they may do and the brand.
 *
 * `can` only shapes the UI. Every endpoint still checks its own policy.
 */
class MeController extends Controller
{
    public function __invoke(
        Request $request,
        ContextSource $source,
        ContextResolver $resolver,
        CurrentContext $context,
        ListAvailableContexts $contexts,
        BrandResolver $brands,
    ): JsonResponse {
        $user = $request->user();
        $active = $this->activeContext($request, $source, $resolver, $context);

        return response()->json(['data' => [
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'context' => $active,
            'contexts' => $contexts->handle($user),
            'can' => $this->abilities($context),
            'brand' => $brands->for($active === null ? null : $context->partner()),
            'locales' => config('tenancy.supported_locales'),
        ]]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeContext(Request $request, ContextSource $source, ContextResolver $resolver, CurrentContext $context): ?array
    {
        $organizationId = $source->organizationId($request);
        $partnerId = $organizationId === null ? $source->partnerId($request) : null;

        try {
            if ($organizationId !== null) {
                $resolver->enterOrganization($request->user(), $organizationId);

                return $this->organizationContext($context, $source->expiresAt($request));
            }

            if ($partnerId !== null) {
                $resolver->enterPartner($request->user(), $partnerId);

                return [
                    'type' => 'partner',
                    'id' => $context->partner()->getKey(),
                    'name' => $context->partner()->name,
                    'role' => $context->partnerUser()->role->value,
                    'expires_at' => $source->expiresAt($request),
                ];
            }
        } catch (TenancyException) {
            // Membership, organization or partner no longer active: the user picks again.
            $source->forgetSession($request);
            $context->clear();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationContext(CurrentContext $context, ?string $expiresAt): array
    {
        $organization = $context->organization();
        $membership = $context->membership();

        return [
            'type' => 'organization',
            'id' => $organization->getKey(),
            'name' => $organization->displayName(),
            'organization_type' => $organization->type->value,
            'membership_type' => $membership->membership_type->value,
            'access_scope' => $membership->access_scope->value,
            'path' => $context->ancestors()
                ->concat([$organization])
                ->map(fn (Organization $node) => [
                    'id' => $node->getKey(),
                    'name' => $node->displayName(),
                    'type' => $node->type->value,
                ])
                ->values()
                ->all(),
            'settings' => [
                'country_code' => $context->country(),
                'default_locale' => $context->locale(),
                'timezone' => $context->timezone(),
                'currency_code' => $context->currency(),
            ],
            'partner' => [
                'id' => $context->partner()->getKey(),
                'name' => $context->partner()->name,
            ],
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(CurrentContext $context): array
    {
        $organization = $context->hasOrganization() ? $context->organization() : null;

        return [
            'organizations.manage' => $organization !== null && Gate::allows('update', $organization),
            'modules.manage' => $organization !== null && Gate::allows('modules.manage', $organization),
            'rules.manage' => $organization !== null && Gate::allows('rules.manage', $organization),
            'partner.rules.manage' => $context->hasPartnerConsole() && $context->partnerUser()->role === PartnerUserRole::Owner,
        ];
    }
}
