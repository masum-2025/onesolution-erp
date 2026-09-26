<?php

namespace App\Platform\Tenancy\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Platform\Access\AccessResolver;
use App\Platform\Access\Models\Role;
use App\Platform\Branding\BrandResolver;
use App\Platform\Partners\HostContext;
use App\Platform\Tenancy\Actions\ListAvailableContexts;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\ContextSource;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        HostContext $host,
        AccessResolver $access,
    ): JsonResponse {
        $user = $request->user();
        $active = $this->activeContext($request, $source, $resolver, $context, $access);

        return response()->json(['data' => [
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'context' => $active,
            'contexts' => $contexts->handle($user),
            'permissions' => $access->effective(),
            'can' => $this->abilities($context, $access),
            // A partner's own address always shows that partner's brand; on the platform
            // address the brand follows the account being worked in.
            'brand' => $brands->for($host->isPlatform() ? ($active === null ? null : $context->partner()) : $host->partner()),
            'locales' => config('tenancy.supported_locales'),
        ]]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeContext(Request $request, ContextSource $source, ContextResolver $resolver, CurrentContext $context, AccessResolver $access): ?array
    {
        $organizationId = $source->organizationId($request);
        $partnerId = $organizationId === null ? $source->partnerId($request) : null;

        try {
            if ($organizationId !== null) {
                $resolver->enterOrganization($request->user(), $organizationId);

                return $this->organizationContext($context, $access, $source->expiresAt($request));
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
    private function organizationContext(CurrentContext $context, AccessResolver $access, ?string $expiresAt): array
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
            'roles' => $this->roles($access),
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
     * Roles the member holds here (names only; permissions are listed separately).
     *
     * @return list<array{id: string, name: string}>
     */
    private function roles(AccessResolver $access): array
    {
        return Role::query()
            ->whereKey($access->roleIds())
            ->get()
            ->map(fn (Role $role) => ['id' => $role->getKey(), 'name' => $role->displayName()])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Every permission that works in the current organization, plus two
     * summaries the UI uses: any rule editing, and the partner console.
     *
     * @return array<string, bool>
     */
    private function abilities(CurrentContext $context, AccessResolver $access): array
    {
        $permissions = $access->effective();

        return [
            ...array_fill_keys($permissions, true),
            'rules.manage' => array_filter($permissions, fn (string $key) => str_starts_with($key, 'rules.edit.')) !== [],
            'partner.rules.manage' => $context->hasPartnerConsole() && $context->partnerUser()->role === PartnerUserRole::Owner,
        ];
    }
}
