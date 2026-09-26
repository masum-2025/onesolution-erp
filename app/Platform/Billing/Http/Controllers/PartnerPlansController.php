<?php

namespace App\Platform\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Billing\Exceptions\BillingException;
use App\Platform\Billing\Http\PartnerPlanPresenter;
use App\Platform\Billing\Http\Requests\BillingReasonRequest;
use App\Platform\Billing\Http\Requests\PartnerPlanRequest;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\Services\PartnerPlanService;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;

/**
 * Partner console: the partner's own plans. Everyone in the console sees
 * them; owners and billing staff make and change them.
 */
class PartnerPlansController extends Controller
{
    use PartnerConsole;

    public function __construct(
        private PartnerPlanService $plans,
        private PartnerPlanPresenter $presenter,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function index(): JsonResponse
    {
        $partner = $this->partner();
        $plans = PartnerPlan::query()->where('partner_id', $partner->getKey())->with('prices')->orderBy('created_at')->get();
        $clients = Subscription::query()->whereIn('partner_plan_id', $plans->modelKeys())->pluck('partner_plan_id')->countBy();

        return response()->json([
            'data' => $plans->map(fn (PartnerPlan $plan) => $this->presenter->plan($plan, (int) ($clients[$plan->getKey()] ?? 0)))->values(),
            'base_plans' => $this->presenter->basePlans($partner),
            'billing_mode' => $partner->billing_mode->value,
            'partner_currency' => $this->rules->get('billing.partner_currency', $this->contexts->forPartner($partner)),
            'can_edit' => $this->hasRole(PartnerUserRole::Owner, PartnerUserRole::Billing),
        ]);
    }

    public function store(PartnerPlanRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Billing);

        $plan = $this->plans->create($this->partner(), $request->validated(), $request->user());

        return response()->json([
            'data' => $this->presenter->plan($plan, 0),
            'message' => __('billing.messages.plan_created', ['name' => $plan->label()]),
        ], 201);
    }

    public function update(PartnerPlanRequest $request, string $plan): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Billing);

        $plan = $this->plans->update($this->find($plan), $request->validated(), $request->user());

        return response()->json([
            'data' => $this->presenter->plan($plan),
            'message' => __('billing.messages.plan_updated', ['name' => $plan->label()]),
        ]);
    }

    public function archive(BillingReasonRequest $request, string $plan): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Billing);

        $plan = $this->plans->archive($this->find($plan), $request->validated('reason'), $request->user());

        return response()->json([
            'data' => $this->presenter->plan($plan),
            'message' => __('billing.messages.plan_archived', ['name' => $plan->label()]),
        ]);
    }

    private function find(string $id): PartnerPlan
    {
        // Another partner's plan is the same 404 as a missing one.
        return PartnerPlan::query()->where('partner_id', $this->partner()->getKey())->whereKey($id)->first()
            ?? throw BillingException::planNotFound();
    }
}
