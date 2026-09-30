<?php

namespace App\Platform\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Billing\Exceptions\BillingException;
use App\Platform\Billing\Http\InvoicePresenter;
use App\Platform\Billing\Models\Commission;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Models\Payout;
use App\Platform\Billing\Services\CurrencyTotals;
use App\Platform\Billing\Services\Payouts;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner console: billing. Invoices we send the partner (wholesale), the
 * invoices its clients get under its brand (direct, revenue share), and its
 * commissions and payouts. Owners and billing staff only; never another
 * partner's documents.
 */
class PartnerBillingController extends Controller
{
    use PartnerConsole;

    public function __construct(
        private InvoicePresenter $presenter,
        private Payouts $payouts,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function summary(): JsonResponse
    {
        $this->requireBillingRole();
        $partner = $this->partner();

        $outstanding = fn (string $billedTo) => collect(CurrencyTotals::of(Invoice::query()
            ->where('partner_id', $partner->getKey())
            ->where('billed_to', $billedTo)
            ->where('type', Invoice::INVOICE)
            ->where('status', Invoice::ISSUED), 'total_minor'))
            ->map(fn (array $row, string $currency) => ['currency' => $currency, 'total_minor' => $row['total'], 'invoices' => $row['count']])
            ->values();

        $pending = collect(CurrencyTotals::of(Commission::query()
            ->where('partner_id', $partner->getKey())
            ->where('status', Commission::PENDING), 'amount_minor'))
            ->map(fn (array $row) => $row['total']);

        $context = $this->contexts->forPartner($partner);

        return response()->json(['data' => [
            'billing_mode' => $partner->billing_mode->value,
            'currency' => $this->rules->get('billing.partner_currency', $context),
            'revenue_share_bp' => (int) $this->rules->get('partners.revenue_share_bp', $context),
            'we_bill_you' => $outstanding(Invoice::TO_PARTNER),
            'clients_owe' => $outstanding(Invoice::TO_ORGANIZATION),
            'commissions' => [
                'payable' => collect($this->payouts->payable($partner))->map(fn ($total, $currency) => ['currency' => $currency, 'total_minor' => $total])->values(),
                'pending' => $pending->map(fn ($total, $currency) => ['currency' => $currency, 'total_minor' => (int) $total])->values(),
            ],
        ]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->requireBillingRole();
        $billedTo = $request->query('billed_to') === Invoice::TO_ORGANIZATION ? Invoice::TO_ORGANIZATION : Invoice::TO_PARTNER;

        $page = Invoice::query()
            ->where('partner_id', $this->partner()->getKey())
            ->where('billed_to', $billedTo)
            ->with('credits')
            ->orderByDesc('issued_at')
            ->orderByDesc('number')
            ->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Invoice $invoice) => $this->presenter->summary($invoice))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function invoice(string $invoice): JsonResponse
    {
        $this->requireBillingRole();

        $invoice = Invoice::query()->where('partner_id', $this->partner()->getKey())->whereKey($invoice)->first()
            ?? throw BillingException::invoiceNotFound();

        return response()->json(['data' => $this->presenter->document($invoice)]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $this->requireBillingRole();

        $page = Commission::query()
            ->where('partner_id', $this->partner()->getKey())
            ->with('invoice')
            ->orderByDesc('created_at')
            ->paginate(PerPage::from($request, 25));

        $clients = Organization::query()->whereKey(collect($page->items())->pluck('organization_id')->unique()->values())->get()
            ->mapWithKeys(fn (Organization $organization) => [$organization->getKey() => $organization->displayName()]);

        return response()->json([
            'data' => collect($page->items())->map(fn (Commission $commission) => [
                'id' => $commission->getKey(),
                'invoice' => ['id' => $commission->invoice_id, 'number' => $commission->invoice?->number, 'type' => $commission->invoice?->type],
                'client' => $clients[$commission->organization_id] ?? null,
                'currency' => $commission->currency_code,
                'rate_bp' => $commission->rate_bp,
                'base_minor' => $commission->base_minor,
                'amount_minor' => $commission->amount_minor,
                'status' => $commission->status,
                'created_at' => $commission->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function payouts(): JsonResponse
    {
        $this->requireBillingRole();

        $payouts = Payout::query()->where('partner_id', $this->partner()->getKey())->withCount('commissions')->orderByDesc('paid_at')->limit(100)->get();

        return response()->json(['data' => $payouts->map(fn (Payout $payout) => [
            'id' => $payout->getKey(),
            'currency' => $payout->currency_code,
            'amount_minor' => $payout->amount_minor,
            'reference' => $payout->reference,
            'commissions' => $payout->commissions_count,
            'paid_at' => $payout->paid_at->toIso8601String(),
        ])->values()]);
    }

    private function requireBillingRole(): void
    {
        if (! $this->hasRole(PartnerUserRole::Owner, PartnerUserRole::Billing)) {
            throw BillingException::roleNotAllowed();
        }
    }
}
