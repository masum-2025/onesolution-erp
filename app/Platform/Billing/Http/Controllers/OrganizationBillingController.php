<?php

namespace App\Platform\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Billing\Exceptions\BillingException;
use App\Platform\Billing\Http\InvoicePresenter;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Branding\BrandResolver;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The client's own billing: its plan, what it costs, and the invoices and
 * credit notes it received. A subscription belongs to the top organization,
 * so this needs billing.view there.
 */
class OrganizationBillingController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private SubscriptionService $subscriptions,
        private InvoicePresenter $presenter,
        private BrandResolver $brands,
    ) {}

    public function show(string $organization): JsonResponse
    {
        $root = $this->root($organization);
        $subscription = $this->subscriptions->for($root);
        $partner = $root->partner;
        // Wholesale: the provider sends its own invoices, outside this system.
        $billedByProvider = $partner->billing_mode === BillingMode::Wholesale;

        $invoices = $billedByProvider ? collect() : Invoice::query()
            ->where('organization_id', $root->getKey())
            ->where('billed_to', Invoice::TO_ORGANIZATION)
            ->with('credits')
            ->orderByDesc('issued_at')
            ->orderByDesc('number')
            ->limit(100)
            ->get();

        return response()->json(['data' => [
            'organization' => ['id' => $root->getKey(), 'name' => $root->displayName()],
            'plan_name' => $this->subscriptions->planName($subscription, $root),
            'currency' => $subscription->currency_code,
            'period' => $subscription->period,
            // Wholesale clients pay their provider its own price, which we do not know.
            'price_minor' => $billedByProvider ? null : $this->subscriptions->price($subscription, $root),
            'billed_through' => $subscription->billed_through?->toDateString(),
            'billed_by_provider' => $billedByProvider,
            // Bought and paid online here (personal workspaces and companies grown from one).
            'self_serve' => $subscription->self_serve && ! $billedByProvider,
            'provider' => $this->brands->for($partner)['name'],
            'invoices' => $invoices->map(fn (Invoice $invoice) => $this->presenter->summary($invoice))->values(),
        ]]);
    }

    public function invoice(string $organization, string $invoice): JsonResponse
    {
        $root = $this->root($organization);

        $invoice = Invoice::query()
            ->where('organization_id', $root->getKey())
            ->where('billed_to', Invoice::TO_ORGANIZATION)
            ->whereKey($invoice)
            ->first() ?? throw BillingException::invoiceNotFound();

        return response()->json(['data' => $this->presenter->document($invoice)]);
    }

    /**
     * The subscription's top organization, which the caller must be able to
     * see and hold billing.view at.
     */
    private function root(string $organization): Organization
    {
        $organization = $this->findVisible($organization);
        $root = $organization->isRoot() ? $organization : Organization::query()->findOrFail($organization->root_id);

        if (! Gate::allows('billing.view', $root)) {
            throw BillingException::topLevelOnly($root->displayName());
        }

        return $root;
    }
}
