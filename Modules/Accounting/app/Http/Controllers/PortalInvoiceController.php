<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Payments\PaymentCollectables;
use App\Platform\Payments\Services\CollectPayment;
use App\Platform\Portal\PortalAccess;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Enums\DocumentType;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Models\Allocation;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Payments\InvoiceCollectable;
use Modules\Accounting\Portal\CustomerSubjects;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Receivables;

/**
 * A portal member's own invoices and credit notes (B2B2C): only those of the
 * customers the company linked to them, only once posted (never drafts,
 * waiting or voided ones), never accounts or journals. Anything else is the
 * same 404. Paying online starts the company's own merchant checkout.
 */
class PortalInvoiceController extends Controller
{
    private const VISIBLE = [DocumentStatus::Posted, DocumentStatus::PartlyPaid, DocumentStatus::Paid];

    public function __construct(
        private Books $books,
        private PortalAccess $portal,
        private CurrentContext $context,
    ) {}

    public function index(Request $request, Receivables $receivables): JsonResponse
    {
        $request->validate(['customer' => ['nullable', 'string', 'size:26']]);
        [$company, $customers] = $this->scope();
        if ($request->filled('customer')) {
            $customers = array_values(array_intersect($customers, [$request->string('customer')->toString()]));
        }

        $parties = $company === null ? collect() : $this->books->query(Party::class, $company)->whereKey($customers)->orderBy('name')->get();
        $documents = $company === null ? collect() : $this->documents($company, $customers)->orderByDesc('issue_date')->limit(200)->get();
        $names = $parties->pluck('name', 'id')->all();

        return response()->json(['data' => [
            'organization' => $this->context->organization()->displayName(),
            'currency' => $company === null ? null : $this->books->currency($company),
            'customers' => $parties->map(fn (Party $party) => [
                'id' => $party->getKey(),
                'name' => $party->name,
                'balance_minor' => $receivables->balanceOf($company, $party, 'sales'),
            ])->values(),
            'documents' => $documents->map(fn (Document $document) => $this->item($document, $names))->values(),
        ]]);
    }

    public function show(string $document): JsonResponse
    {
        [$company, $customers] = $this->scope();
        $found = $company === null ? null : $this->documents($company, $customers)->whereKey($document)->first();
        if ($found === null) {
            throw AccountingException::documentNotFound();
        }

        $party = $this->books->query(Party::class, $company)->findOrFail($found->party_id);
        $paid = $this->books->query(Allocation::class, $company)
            ->where($found->type->isCredit() ? 'credit_document_id' : 'document_id', $found->getKey())
            ->whereNull('voided_at')->orderBy('allocated_on')->get();

        return response()->json(['data' => [
            ...$this->item($found, [$party->getKey() => $party->name]),
            'organization' => $company->displayName(),
            'notes' => $found->notes,
            'lines' => $this->books->query(DocumentLine::class, $company)->where('document_id', $found->getKey())->orderBy('line_no')->get()
                ->map(fn (DocumentLine $line) => [
                    'description' => $line->description,
                    'quantity' => AccountingPresenter::quantityText($line->quantity_milli),
                    'unit_price_minor' => $line->unit_price_minor,
                    'amount_minor' => $line->amount_minor,
                    'tax_rate_bp' => $line->tax_rate_bp,
                    'tax_minor' => $line->tax_minor,
                ])->values(),
            'payments' => $paid->map(fn (Allocation $allocation) => ['on' => $allocation->allocated_on->toDateString(), 'amount_minor' => $allocation->amount_minor])->values(),
            'can_pay' => $this->canPay($company, $found),
        ]]);
    }

    public function pay(Request $request, string $document, CollectPayment $collect): JsonResponse
    {
        $request->validate(['op_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/']]);
        [$company, $customers] = $this->scope();
        $found = $company === null ? null : $this->documents($company, $customers)->whereKey($document)->first();
        if ($found === null) {
            throw AccountingException::documentNotFound();
        }

        // The amount comes from the invoice (InvoiceCollectable::due), never from the request.
        $payment = $collect->start($company, $request->user(), InvoiceCollectable::KEY, $found->getKey(), $request->string('op_id')->toString());

        return response()->json(['data' => ['payment_id' => $payment->getKey(), 'checkout_url' => $payment->checkout_url, 'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency_code]], 201);
    }

    /**
     * The books and the customers the member is linked to; nothing for staff
     * (who use the staff screens) or where no books are kept.
     *
     * @return array{0: Organization|null, 1: list<string>}
     */
    private function scope(): array
    {
        $organization = $this->context->organization();
        if (! $this->portal->isPortal()
            || ! in_array($organization->type, [OrganizationType::Company, OrganizationType::Personal], true)
            || ! $this->books->isSetUp($organization)) {
            return [null, []];
        }

        return [$organization, $this->portal->subjectIds(CustomerSubjects::KEY)];
    }

    /**
     * @param  list<string>  $customers
     */
    private function documents(Organization $company, array $customers)
    {
        return $this->books->query(Document::class, $company)
            ->whereIn('party_id', $customers)
            ->whereIn('type', [DocumentType::Invoice->value, DocumentType::CreditNote->value])
            ->whereIn('status', array_map(fn (DocumentStatus $status) => $status->value, self::VISIBLE));
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function item(Document $document, array $names): array
    {
        return [
            'id' => $document->getKey(),
            'type' => $document->type->value,
            'number' => $document->number,
            'customer' => $names[$document->party_id] ?? null,
            'issue_date' => $document->issue_date->toDateString(),
            'due_date' => $document->type->isCredit() ? null : $document->due_date->toDateString(),
            'status' => $document->status->value,
            'currency' => $document->currency_code,
            'net_minor' => $document->net_minor,
            'tax_minor' => $document->tax_minor,
            'total_minor' => $document->total_minor,
            'balance_minor' => $document->balance(),
        ];
    }

    /** Online payment is offered for an unpaid invoice while the company takes payments and allows them in the portal. */
    private function canPay(Organization $company, Document $document): bool
    {
        return $document->type === DocumentType::Invoice
            && $document->balance() > 0
            && app(PaymentCollectables::class)->usable(InvoiceCollectable::KEY, $company)
            && (bool) app(RuleResolver::class)->get('client_portal.allow_online_payment', app(RuleContextFactory::class)->forOrganization($company));
    }
}
