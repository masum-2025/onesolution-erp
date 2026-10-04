<?php

namespace Modules\Accounting\Payments;

use App\Models\User;
use App\Platform\Payments\CollectionDue;
use App\Platform\Payments\Contracts\CollectableProvider;
use App\Platform\Payments\Models\Payment;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Modules\Accounting\Enums\DocumentType;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Portal\CustomerSubjects;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Settlements;

/**
 * Customers pay their invoices online (online_payments, the company's own
 * merchant account). What is due is the invoice's balance, and only for
 * the customer the payer is linked to in the portal, while the company
 * allows online payment there (rule client_portal.allow_online_payment).
 * Confirmed money becomes a receipt set against the invoice.
 */
class InvoiceCollectable implements CollectableProvider
{
    public const KEY = 'accounting.invoice';

    public function __construct(
        private Books $books,
        private Settlements $settlements,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(?string $locale = null): string
    {
        return __('accounting::accounting.kinds.invoice', [], $locale);
    }

    public function due(Organization $organization, string $id, User $payer): ?CollectionDue
    {
        $invoice = $this->invoice($organization, $id);
        if ($invoice === null || $invoice->balance() <= 0 || ! $this->payerIsCustomer($organization, $invoice, $payer)) {
            return null;
        }
        if (! (bool) $this->rules->get('client_portal.allow_online_payment', $this->contexts->forOrganization($organization))) {
            return null;
        }

        return new CollectionDue($invoice->balance(), $invoice->currency_code);
    }

    public function paid(Payment $payment): void
    {
        $company = Organization::query()->findOrFail($payment->organization_id);
        /** @var Document $invoice */
        $invoice = $this->books->query(Document::class, $company)->findOrFail($payment->subject_id);

        $this->settlements->recordOnline($company, $invoice, (int) $payment->amount_minor, (string) ($payment->gateway_ref ?? $payment->getKey()), 'payment-'.$payment->getKey());
    }

    public function returnPath(Payment $payment): string
    {
        return "/portal/invoices/{$payment->subject_id}";
    }

    /** A posted (not voided) invoice of the company's books. */
    private function invoice(Organization $organization, string $id): ?Document
    {
        if (! $this->books->isSetUp($organization)) {
            return null;
        }
        $invoice = $this->books->query(Document::class, $organization)->whereKey($id)->where('type', DocumentType::Invoice->value)->first();

        return $invoice !== null && $invoice->status->isPosted() ? $invoice : null;
    }

    /** The payer's own portal membership here is linked to the invoice's customer. */
    private function payerIsCustomer(Organization $organization, Document $invoice, User $payer): bool
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $payer->getKey())
            ->where('membership_type', MembershipType::Portal->value)
            ->first();

        return $membership !== null && $membership->isActive() && PortalLink::query()
            ->where('membership_id', $membership->getKey())
            ->where('organization_id', $organization->getKey())
            ->where('subject_type', CustomerSubjects::KEY)
            ->where('subject_id', $invoice->party_id)
            ->where('status', PortalLink::ACTIVE)
            ->exists();
    }
}
