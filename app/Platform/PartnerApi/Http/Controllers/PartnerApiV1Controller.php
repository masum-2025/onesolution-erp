<?php

namespace App\Platform\PartnerApi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Billing\Http\PartnerPlanPresenter;
use App\Platform\Invitations\Services\InvitationService;
use App\Platform\Packaging\Actions\ChangePlan;
use App\Platform\Packaging\Actions\PlanChoice;
use App\Platform\Packaging\Exceptions\PackagingException;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\Models\PartnerPlanPrice;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\PartnerApi\Exceptions\ApiException;
use App\Platform\PartnerApi\Http\Requests\ApiMemberRequest;
use App\Platform\PartnerApi\Http\Requests\ApiPlanRequest;
use App\Platform\Partners\Http\Requests\StoreClientRequest;
use App\Platform\Partners\Services\ClientProvisioner;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Http\Resources\PartnerOrganizationResource;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner API v1, for a partner's own systems (website, CRM): its clients,
 * their members and their plans. Account data only, never a client's
 * business data. Every write is audited with the key that made it.
 */
class PartnerApiV1Controller extends Controller
{
    public function __construct(private CurrentContext $context) {}

    public function clients(Request $request): JsonResponse
    {
        $page = Organization::query()
            ->where('partner_id', $this->context->partner()->getKey())
            ->whereNull('parent_id')
            ->orderBy('created_at')
            ->paginate(max(1, min($request->integer('per_page', 50), 100)));

        return response()->json([
            'data' => PartnerOrganizationResource::collection($page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function client(string $client, SubscriptionService $subscriptions): JsonResponse
    {
        $root = $this->find($client);
        $subscription = $subscriptions->for($root);

        return response()->json(['data' => [
            ...(new PartnerOrganizationResource($root))->resolve(),
            'subscription' => [
                'plan' => $subscriptions->planKey($root),
                'partner_plan_id' => $subscription->partner_plan_id,
                'currency' => $subscription->currency_code,
                'period' => $subscription->period,
            ],
        ]]);
    }

    public function storeClient(StoreClientRequest $request, ClientProvisioner $provisioner): JsonResponse
    {
        $created = $provisioner->create($this->context->partner(), $request->validated(), $request->user());

        return response()->json([
            'data' => new PartnerOrganizationResource($created['group']),
            'company' => new PartnerOrganizationResource($created['company']),
            'owner_invited' => $created['owner_invited'],
        ], 201);
    }

    public function storeMember(ApiMemberRequest $request, string $client, InvitationService $invitations): JsonResponse
    {
        $root = $this->find($client);
        $unit = $request->validated('organization_id') === null
            ? $root
            : Organization::query()->where('root_id', $root->getKey())->whereKey($request->validated('organization_id'))->first() ?? throw ApiException::notFound();

        $made = $invitations->invite(
            $unit,
            $request->validated('email'),
            $request->validated('name'),
            MembershipType::from($request->validated('membership_type')),
            AccessScope::from($request->validated('access_scope', 'descendants')),
            $request->user(),
        );

        return response()->json(['data' => [
            'membership_id' => $made['membership']->getKey(),
            'user_id' => $made['user']->getKey(),
            'organization_id' => $unit->getKey(),
            'invited' => $made['invited'],
        ]], 201);
    }

    public function plans(PartnerPlanPresenter $presenter): JsonResponse
    {
        $partner = $this->context->partner();

        return response()->json([
            'data' => PartnerPlan::query()->where('partner_id', $partner->getKey())->where('status', PartnerPlan::ACTIVE)->with('prices')->get()
                ->map(fn (PartnerPlan $plan) => [
                    'id' => $plan->getKey(),
                    'name' => $plan->name,
                    'base_plan' => $plan->base_plan_key,
                    'modules' => $plan->modules,
                    'prices' => $plan->prices->map(fn (PartnerPlanPrice $price) => ['currency' => $price->currency_code, 'period' => $price->period, 'amount_minor' => $price->amount_minor])->values(),
                ])->values(),
            'base_plans' => array_map(fn (array $plan) => ['key' => $plan['key'], 'name' => $plan['name'], 'list_prices' => $plan['list_prices']], $presenter->basePlans($partner)),
        ]);
    }

    public function changePlan(ApiPlanRequest $request, string $client, ChangePlan $plans): JsonResponse
    {
        $root = $this->find($client);

        $partnerPlan = null;
        if ($request->validated('partner_plan_id') !== null) {
            $partnerPlan = PartnerPlan::query()->where('partner_id', $this->context->partner()->getKey())->whereKey($request->validated('partner_plan_id'))->first()
                ?? throw PackagingException::unknownPlan();
        }

        $key = $request->attributes->get('partner_api_key');
        $summary = $plans->handle(
            $root,
            new PlanChoice((string) $request->validated('plan'), $partnerPlan, $request->validated('currency'), $request->validated('period')),
            $request->validated('reason') ?? "Changed through the API ({$key->name})",
            $request->user(),
        );

        return response()->json(['data' => $summary]);
    }

    private function find(string $id): Organization
    {
        // Another partner's client is the same 404 as a missing one.
        return Organization::query()->where('partner_id', $this->context->partner()->getKey())->whereNull('parent_id')->whereKey($id)->first()
            ?? throw ApiException::notFound();
    }
}
