<?php

namespace App\Platform\Notifications\Listeners;

use App\Models\User;
use App\Platform\Billing\Events\InvoiceIssued;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\SelfServe\Events\PaymentOverdue;
use App\Platform\Billing\SelfServe\Events\TrialEnded;
use App\Platform\Billing\SelfServe\Events\TrialEnding;
use App\Platform\Billing\SelfServe\Events\WorkspaceRestored;
use App\Platform\Billing\SelfServe\Events\WorkspaceRestricted;
use App\Platform\DataExport\Events\DataExportReady;
use App\Platform\Legal\Events\LegalDocumentPublished;
use App\Platform\Legal\Models\LegalDocument;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Payments\Events\MerchantAccountChangeApplied;
use App\Platform\Payments\Events\MerchantAccountChangeRequested;
use App\Platform\Payments\Events\PaymentFailed;
use App\Platform\Payments\Events\PaymentSucceeded;
use App\Platform\Payments\Models\Payment;
use App\Platform\Support\MoneyText;
use App\Platform\SupportAccess\Enums\GrantStatus;
use App\Platform\SupportAccess\Events\SupportAccessDecided;
use App\Platform\SupportAccess\Events\SupportAccessRequested;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Transfers\Events\ClientTransferred;
use App\Platform\Transfers\Events\ClientTransferRequested;
use Carbon\CarbonInterface;

/**
 * Turns platform events into notifications for the people who can act on
 * them. Messages are queued (Notifier), so this listener stays quick.
 */
class SendPlatformNotifications
{
    public function __construct(private Notifier $notifier, private Recipients $recipients, private PlanCatalog $plans) {}

    public function supportRequested(SupportAccessRequested $event): void
    {
        $grant = $event->grant->loadMissing(['organization', 'partner']);
        $staff = User::query()->find($grant->requested_by);

        $this->notifier->notify(
            'support.requested',
            $this->recipients->holding('support.approve', $grant->organization),
            fn (string $locale) => [
                'organization' => $this->name($grant->organization, $locale),
                'partner' => $grant->partner->name,
                'staff' => $staff?->name ?? '—',
                'severity' => __("notifications.severity.{$grant->severity->value}", [], $locale),
                'minutes' => (string) $grant->duration_minutes,
                'reason' => $grant->reason,
            ],
            $grant->partner,
            $grant->organization,
        );
    }

    public function supportDecided(SupportAccessDecided $event): void
    {
        $grant = $event->grant->loadMissing(['organization', 'partner']);
        $staff = User::query()->whereKey($grant->requested_by)->get();
        $approved = $grant->status === GrantStatus::Approved;

        // The requester reads it in the partner console, at the partner's address.
        $this->notifier->notify(
            'support.decided',
            $staff,
            fn (string $locale) => [
                'organization' => $this->name($grant->organization, $locale),
                'decision' => __('notifications.decisions.'.($approved ? 'approved' : 'rejected'), [], $locale),
                'reason' => $grant->decision_reason ?? '—',
                'minutes' => (string) $grant->duration_minutes,
            ],
            $grant->partner,
        );
    }

    public function exportReady(DataExportReady $event): void
    {
        $export = $event->export->loadMissing('organization');
        $organization = $export->organization;

        $this->notifier->notify(
            'exports.ready',
            User::query()->whereKey($export->requested_by)->get(),
            fn (string $locale) => [
                'organization' => $this->name($organization, $locale),
                'expires' => $this->day($export->expires_at, $locale),
            ],
            $organization->partner,
            $organization,
        );
    }

    public function invoiceIssued(InvoiceIssued $event): void
    {
        $invoice = $event->invoice->loadMissing(['organization', 'partner']);

        // Issued and paid in one step at an online checkout: the receipt says it all.
        if (str_starts_with((string) $invoice->billing_key, 'payment:')) {
            return;
        }
        $values = fn (string $locale) => [
            'number' => $invoice->number,
            'amount' => MoneyText::format($invoice->total_minor, $invoice->currency_code, $locale),
            'due' => $this->day($invoice->due_at, $locale),
        ];

        if ($invoice->billed_to === Invoice::TO_ORGANIZATION) {
            $this->notifier->notify(
                'billing.invoice_issued',
                $this->recipients->holding('billing.view', $invoice->organization),
                fn (string $locale) => ['organization' => $this->name($invoice->organization, $locale), ...$values($locale)],
                $invoice->partner,
                $invoice->organization,
            );

            return;
        }

        // Our invoice to a wholesale partner comes from us, in our brand.
        $this->notifier->notify(
            'billing.partner_invoice_issued',
            $this->recipients->partnerStaff($invoice->partner, PartnerUserRole::Owner, PartnerUserRole::Billing),
            fn (string $locale) => ['partner' => $invoice->partner->name, ...$values($locale)],
            null,
        );
    }

    public function paymentSucceeded(PaymentSucceeded $event): void
    {
        // A customer paying a client (Phase 6): the module that owns the record tells them.
        if ($event->payment->purpose === Payment::COLLECTION) {
            return;
        }

        $payment = $event->payment->loadMissing(['organization.partner', 'invoice']);
        $organization = $payment->organization;

        $this->notifier->notify(
            'billing.payment_received',
            $this->recipients->holding('billing.view', $organization),
            fn (string $locale) => [
                'organization' => $this->name($organization, $locale),
                'amount' => MoneyText::format($payment->amount_minor, $payment->currency_code, $locale),
                'number' => $payment->invoice?->number ?? '—',
                'method' => $payment->method ?? '—',
            ],
            $organization->partner,
            $organization,
        );
    }

    public function paymentFailed(PaymentFailed $event): void
    {
        $payment = $event->payment->loadMissing('organization.partner');

        // A cancel is the person's own choice, made on screen: no message for it.
        // A customer's payment to a client (Phase 6) is the owning module's to tell.
        if ($payment->status !== Payment::FAILED || $payment->purpose === Payment::COLLECTION) {
            return;
        }

        $this->notifier->notify(
            'billing.payment_failed',
            User::query()->whereKey($payment->user_id)->get(),
            fn (string $locale) => [
                'organization' => $this->name($payment->organization, $locale),
                'amount' => MoneyText::format($payment->amount_minor, $payment->currency_code, $locale),
            ],
            $payment->organization->partner,
            $payment->organization,
        );
    }

    /**
     * Where a client's customers' money goes is changing: everyone who could
     * approve (or reject) it hears at once, on every channel.
     */
    public function merchantChangeRequested(MerchantAccountChangeRequested $event): void
    {
        $account = $event->account;
        $company = Organization::query()->with('partner')->findOrFail($account->organization_id);

        $this->notifier->notify(
            'payments.merchant_change_requested',
            $this->recipients->holding('online_payments.manage', $company),
            fn (string $locale) => [
                'organization' => $this->name($company, $locale),
                'gateway' => __('payments.gateways.'.$account->gateway, [], $locale),
                'person' => $event->actor->name,
                'takes_effect' => $account->activates_at === null
                    ? __('payments.merchant.takes_effect_after_approval', [], $locale)
                    : __('payments.merchant.takes_effect_on', ['date' => $account->activates_at->locale($locale)->isoFormat('D MMMM YYYY, HH:mm').' UTC'], $locale),
            ],
            $company->partner,
            $company,
            allChannels: true,
        );
    }

    public function merchantChangeApplied(MerchantAccountChangeApplied $event): void
    {
        $account = $event->account;
        $company = Organization::query()->with('partner')->findOrFail($account->organization_id);

        $this->notifier->notify(
            'payments.merchant_change_applied',
            $this->recipients->holding('online_payments.manage', $company),
            fn (string $locale) => [
                'organization' => $this->name($company, $locale),
                'gateway' => __('payments.gateways.'.$account->gateway, [], $locale),
                'person' => $event->approver === null
                    ? __('payments.merchant.applied_after_wait', [], $locale)
                    : __('payments.merchant.approved_by', ['name' => $event->approver->name], $locale),
            ],
            $company->partner,
            $company,
        );
    }

    public function trialEnding(TrialEnding $event): void
    {
        $organization = $event->organization->loadMissing('partner');

        $this->notifier->notify(
            'billing.trial_ending',
            $this->recipients->holding('billing.manage', $organization),
            fn (string $locale) => [
                'organization' => $this->name($organization, $locale),
                'plan' => $this->plans->has($event->planKey) ? $this->plans->get($event->planKey)->label($locale) : $event->planKey,
                'ends' => $this->day($event->endsAt, $locale),
            ],
            $organization->partner,
            $organization,
        );
    }

    public function trialEnded(TrialEnded $event): void
    {
        $organization = $event->organization->loadMissing('partner');

        $this->notifier->notify(
            'billing.trial_ended',
            $this->recipients->holding('billing.manage', $organization),
            fn (string $locale) => [
                'organization' => $this->name($organization, $locale),
                'plan' => $this->plans->has($event->planKey) ? $this->plans->get($event->planKey)->label($locale) : $event->planKey,
            ],
            $organization->partner,
            $organization,
        );
    }

    public function paymentOverdue(PaymentOverdue $event): void
    {
        $organization = $event->organization->loadMissing('partner');
        $invoice = $event->invoice;

        $this->notifier->notify(
            'billing.payment_overdue',
            $this->recipients->holding('billing.manage', $organization),
            fn (string $locale) => [
                'organization' => $this->name($organization, $locale),
                'number' => $invoice->number,
                'amount' => MoneyText::format($invoice->total_minor, $invoice->currency_code, $locale),
                'due' => $this->day($invoice->due_at, $locale),
                'read_only_on' => $this->day($event->restrictsOn, $locale),
            ],
            $organization->partner,
            $organization,
        );
    }

    public function workspaceRestricted(WorkspaceRestricted $event): void
    {
        $this->accountNotice('billing.workspace_restricted', $event->organization);
    }

    public function workspaceRestored(WorkspaceRestored $event): void
    {
        $this->accountNotice('billing.workspace_restored', $event->organization);
    }

    private function accountNotice(string $key, Organization $organization): void
    {
        $organization->loadMissing('partner');

        $this->notifier->notify(
            $key,
            $this->recipients->holding('billing.manage', $organization),
            fn (string $locale) => ['organization' => $this->name($organization, $locale)],
            $organization->partner,
            $organization,
        );
    }

    public function transferRequested(ClientTransferRequested $event): void
    {
        $transfer = $event->transfer->loadMissing(['organization', 'toPartner']);

        $this->notifier->notify(
            'transfers.requested',
            $this->recipients->partnerStaff($transfer->toPartner, PartnerUserRole::Owner),
            fn (string $locale) => ['organization' => $this->name($transfer->organization, $locale), 'reason' => $transfer->reason],
            $transfer->toPartner,
        );
    }

    public function transferred(ClientTransferred $event): void
    {
        $transfer = $event->transfer->loadMissing(['organization', 'fromPartner', 'toPartner']);
        $root = $transfer->organization;

        // The client hears it in its new provider's brand.
        $this->notifier->notify(
            'transfers.completed',
            $this->recipients->accountOwners($root),
            fn (string $locale) => ['organization' => $this->name($root, $locale), 'partner' => $transfer->toPartner->name],
            $transfer->toPartner,
            $root,
        );

        // The old partner is told, without naming where the client went.
        $this->notifier->notify(
            'transfers.client_left',
            $this->recipients->partnerStaff($transfer->fromPartner, PartnerUserRole::Owner),
            fn (string $locale) => ['organization' => $this->name($root, $locale)],
            $transfer->fromPartner,
        );
    }

    /**
     * A new version of terms or the DPA: every client bound by it is asked to accept.
     */
    public function legalPublished(LegalDocumentPublished $event): void
    {
        $document = $event->document;
        if (! in_array($document->kind, LegalDocument::ACCEPTED_KINDS, true)) {
            return;
        }

        $clients = Organization::query()->whereNull('parent_id')->where('status', OrganizationStatus::Active)
            ->when($document->partner_id !== null,
                fn ($query) => $query->where('partner_id', $document->partner_id),
                // The platform's own version binds clients of partners without their own.
                fn ($query) => $query->whereNotIn('partner_id', LegalDocument::query()->whereNotNull('partner_id')->where('kind', $document->kind)->select('partner_id')));

        foreach ($clients->with('partner')->lazyById() as $root) {
            if (! in_array($document->kind, LegalDocument::acceptedKindsFor($root), true)) {
                continue;
            }
            $this->notifier->notify(
                'legal.updated',
                $this->recipients->accountOwners($root),
                fn (string $locale) => [
                    'organization' => $this->name($root, $locale),
                    'document' => $document->text('title', $locale),
                    'summary' => (string) $document->summary,
                ],
                $root->partner,
                $root,
            );
        }
    }

    private function name(Organization $organization, string $locale): string
    {
        $name = (array) $organization->name;

        return (string) ($name[$locale] ?? $name['en'] ?? reset($name));
    }

    private function day(?CarbonInterface $date, string $locale): string
    {
        return $date === null ? '—' : $date->locale($locale)->isoFormat('D MMMM YYYY');
    }
}
