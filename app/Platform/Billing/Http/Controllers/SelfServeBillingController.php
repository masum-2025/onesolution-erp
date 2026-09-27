<?php

namespace App\Platform\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Billing\Exceptions\BillingException;
use App\Platform\Billing\Http\Requests\CheckoutRequest;
use App\Platform\Billing\Http\Requests\ConfirmedRequest;
use App\Platform\Billing\Http\Requests\EmptyRequest;
use App\Platform\Billing\Http\Requests\PayInvoiceRequest;
use App\Platform\Billing\Http\Requests\SelfServePlanRequest;
use App\Platform\Billing\Http\SelfServePresenter;
use App\Platform\Billing\SelfServe\Checkout;
use App\Platform\Billing\SelfServe\PlanSwitch;
use App\Platform\Billing\SelfServe\Quotes;
use App\Platform\Billing\SelfServe\SelfServeAccount;
use App\Platform\Billing\SelfServe\Trials;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\Services\PaymentReconciler;
use App\Platform\Support\LocalDate;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Self-serve billing (Phase 5C-2): a personal workspace buys, pays for and
 * changes its own plan. Seeing needs billing.view, acting billing.manage
 * (the owner holds both). The workspace always comes from the context.
 */
class SelfServeBillingController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private SelfServeAccount $account, private SelfServePresenter $presenter) {}

    public function show(Request $request, string $organization): JsonResponse
    {
        $root = $this->root($organization, 'billing.view');

        return $this->state($root, $request);
    }

    public function quote(SelfServePlanRequest $request, string $organization, Quotes $quotes): JsonResponse
    {
        $root = $this->root($organization, 'billing.manage');

        return response()->json(['data' => $quotes->quote($root, $request->validated('plan_key'), $request->validated('period'))->toArray()]);
    }

    public function checkout(CheckoutRequest $request, string $organization, Checkout $checkout): JsonResponse
    {
        $root = $this->root($organization, 'billing.manage');
        $payment = $checkout->forPlan($root, $request->user(), $request->validated('plan_key'), $request->validated('period'), $request->validated('op_id'));

        return response()->json(['data' => $this->presenter->payment($payment)], $payment->wasRecentlyCreated ? 201 : 200);
    }

    public function payInvoice(PayInvoiceRequest $request, string $organization, string $invoice, Checkout $checkout): JsonResponse
    {
        $root = $this->root($organization, 'billing.manage');
        $payment = $checkout->forInvoice($root, $request->user(), $invoice, $request->validated('op_id'));

        return response()->json(['data' => $this->presenter->payment($payment)], $payment->wasRecentlyCreated ? 201 : 200);
    }

    public function payment(string $organization, string $payment, PaymentReconciler $reconciler): JsonResponse
    {
        $root = $this->root($organization, 'billing.view');

        $found = Payment::query()->where('organization_id', $root->getKey())->whereKey($payment)->first()
            ?? throw PaymentException::paymentNotFound();

        // Still open: ask the gateway (at most every few seconds), in case its notice is late.
        $found = $reconciler->check($found);

        return response()->json(['data' => $this->presenter->payment($found->load('invoice'))]);
    }

    public function trial(EmptyRequest $request, string $organization, Trials $trials): JsonResponse
    {
        $root = $this->root($organization, 'billing.manage');
        $trials->start($root, $request->user());

        return $this->state($root->refresh(), $request, __('payments.messages.trial_started'));
    }

    public function toFree(ConfirmedRequest $request, string $organization, PlanSwitch $switch): JsonResponse
    {
        $root = $this->root($organization, 'billing.manage');
        $result = $switch->toFree($root, $request->user());

        $message = $result['when'] === 'now'
            ? __('payments.messages.moved_to_free')
            : __('payments.messages.moves_to_free', ['date' => LocalDate::format(CarbonImmutable::parse($result['on'])->addDay())]);

        return $this->state($root->refresh(), $request, $message);
    }

    public function keepPlan(EmptyRequest $request, string $organization, PlanSwitch $switch): JsonResponse
    {
        $root = $this->root($organization, 'billing.manage');
        $switch->keep($root, $request->user());

        return $this->state($root->refresh(), $request, __('payments.messages.plan_kept'));
    }

    private function state(Organization $root, Request $request, ?string $message = null): JsonResponse
    {
        $subscription = $this->account->subscription($root);

        return response()->json(array_filter([
            'data' => $this->presenter->state($root, $subscription, $request->user()),
            'message' => $message,
        ], fn ($value) => $value !== null));
    }

    /**
     * The account's top organization, which the caller must see and hold
     * the permission at.
     */
    private function root(string $organization, string $permission): Organization
    {
        $organization = $this->findVisible($organization);
        $root = $organization->isRoot() ? $organization : Organization::query()->findOrFail($organization->root_id);

        if (! Gate::allows($permission, $root)) {
            throw $permission === 'billing.view'
                ? BillingException::topLevelOnly($root->displayName())
                : PaymentException::notAllowed();
        }

        return $root->loadMissing('partner');
    }
}
