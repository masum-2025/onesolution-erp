<?php

namespace App\Platform\Tenancy\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Platform\Access\AccessResolver;
use App\Platform\Access\Models\Role;
use App\Platform\Branding\BrandResolver;
use App\Platform\Legal\Services\LegalService;
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
                // Self-serve people may have only a phone (Phase 5C).
                'phone' => $user->phone,
                'onboarded' => $user->onboarded_at !== null,
                // An account deletion is waiting (Phase 5C-3): the app shows it everywhere.
                'deletion_due_at' => $user->deletion_due_at?->toIso8601String(),
            ],
            'context' => $active,
            'contexts' => $contexts->handle($user),
            'permissions' => $this->usablePermissions($context, $access),
            'can' => $this->abilities($context, $access),
            // A partner's own address always shows that partner's brand; on the platform
            // address the brand follows the account being worked in.
            'brand' => $brands->for(
                $host->isPlatform() ? ($active === null ? null : $context->partner()) : $host->partner(),
                $context->hasOrganization() ? $context->organization() : $host->client(),
            ),
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
        $grantId = $organizationId === null && $partnerId === null ? $source->supportGrantId($request) : null;

        try {
            if ($organizationId !== null) {
                $resolver->enterOrganization($request->user(), $organizationId);

                return $this->organizationContext($context, $access, $source->expiresAt($request));
            }

            if ($grantId !== null) {
                $resolver->enterSupport($request->user(), $grantId);

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
            // normal | read_only | export_only, and why (support, partner_suspended, payment_overdue).
            'mode' => $context->mode(),
            'mode_reason' => $context->modeReason(),
            'mode_until' => $context->modeUntil()?->toIso8601String(),
            'support' => $context->isSupport() ? [
                'grant_id' => $context->supportGrant()->getKey(),
                'expires_at' => $context->supportGrant()->expires_at?->toIso8601String(),
            ] : null,
            // The owner of the whole account: accepts legal documents, may move provider.
            'account_owner' => $accountOwner = ! $context->isSupport() && $membership->isOwner() && $organization->isRoot(),
            'legal_pending' => $accountOwner ? count(app(LegalService::class)->pending($organization)) : 0,
        ];
    }

    /**
     * What the UI may offer. In a read-only or export-only context only the
     * reading and exporting permissions are listed; the server refuses any
     * change there anyway.
     *
     * @return list<string>
     */
    private function usablePermissions(CurrentContext $context, AccessResolver $access): array
    {
        $permissions = $access->effective();

        if (! $context->hasOrganization() || $context->mode() === CurrentContext::MODE_NORMAL) {
            return $permissions;
        }

        $usable = ['audit.view', 'data.export'];
        // Read-only for an overdue bill: settling it stays possible (Phase 5C-2).
        if ($context->modeReason() === 'payment_overdue') {
            $usable = [...$usable, 'billing.view', 'billing.manage'];
        }

        return array_values(array_intersect($permissions, $usable));
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
        $permissions = $this->usablePermissions($context, $access);

        return [
            ...array_fill_keys($permissions, true),
            'rules.manage' => array_filter($permissions, fn (string $key) => str_starts_with($key, 'rules.edit.')) !== [],
            'partner.rules.manage' => $context->hasPartnerConsole() && $context->partnerUser()->role === PartnerUserRole::Owner,
        ];
    }
}
