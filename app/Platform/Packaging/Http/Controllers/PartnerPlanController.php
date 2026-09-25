<?php

namespace App\Platform\Packaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Packaging\Actions\ChangePlan;
use App\Platform\Packaging\Http\Requests\ChangePlanRequest;
use App\Platform\Packaging\Http\Requests\PreviewPlanRequest;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Exceptions\OrganizationNotFound;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

/**
 * Partner console: move a client subscription to another plan. Only partner
 * owners and billing staff, only their own clients, only at the top
 * organization of a subscription.
 */
class PartnerPlanController extends Controller
{
    public function __construct(private CurrentContext $context, private ChangePlan $plans) {}

    public function preview(PreviewPlanRequest $request, string $organization): JsonResponse
    {
        $root = $this->client($organization);

        return response()->json(['data' => $this->plans->preview($root, $request->validated('plan'))]);
    }

    public function update(ChangePlanRequest $request, string $organization): JsonResponse
    {
        $root = $this->client($organization);

        $summary = $this->plans->handle($root, $request->validated('plan'), $request->validated('reason'), $request->user());

        return response()->json([
            'data' => $summary,
            'message' => __('packaging.messages.plan_changed', ['plan' => $summary['plan']['name']]),
        ]);
    }

    private function client(string $organizationId): Organization
    {
        if (! in_array($this->context->partnerUser()->role, [PartnerUserRole::Owner, PartnerUserRole::Billing], true)) {
            throw new AuthorizationException(__('tenancy.errors.forbidden'));
        }

        // Another partner's client is the same 404 as a missing one.
        return Organization::query()
            ->where('partner_id', $this->context->partner()->getKey())
            ->whereKey($organizationId)
            ->first() ?? throw new OrganizationNotFound;
    }
}
