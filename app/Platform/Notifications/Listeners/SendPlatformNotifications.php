<?php

namespace App\Platform\Notifications\Listeners;

use App\Models\User;
use App\Platform\Billing\Events\InvoiceIssued;
use App\Platform\Billing\Models\Invoice;
use App\Platform\DataExport\Events\DataExportReady;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Support\MoneyText;
use App\Platform\SupportAccess\Enums\GrantStatus;
use App\Platform\SupportAccess\Events\SupportAccessDecided;
use App\Platform\SupportAccess\Events\SupportAccessRequested;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Events\Dispatcher;

/**
 * Turns platform events into notifications for the people who can act on
 * them. Messages are queued (Notifier), so this listener stays quick.
 */
class SendPlatformNotifications
{
    public function __construct(private Notifier $notifier, private Recipients $recipients) {}

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
