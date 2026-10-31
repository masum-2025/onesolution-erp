<?php

namespace Modules\Compliance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Collection;
use Modules\Accounting\Services\Bills;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Models\ReceivedInvoice;
use Modules\Compliance\Models\RenewalRequest;
use Modules\Compliance\Support\Validity;

/**
 * Invoices a connected provider sends (projects.invoice, optional: only
 * over a business connection). One copy per invoice, kept as it last came:
 * the provider sends it again when it is paid or voided. When the client
 * keeps books (Accounting), someone holding compliance.bills (and
 * accounting.buy) takes it in once as a draft bill: the provider as a
 * vendor (one per connection), the lines on compliance.audit_fees, the
 * factory as the cost centre. Nothing goes back to the provider.
 */
class ProviderInvoices
{
    public const KIND = 'projects.invoice';

    public const EXPENSE_KEY = 'compliance.audit_fees';

    public function __construct(
        private Place $place,
        private Bills $bills,
        private Recipients $recipients,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, ReceivedInvoice>
     */
    public function list(Organization $unit, bool $openOnly = false): Collection
    {
        return $this->place->query(ReceivedInvoice::class, $unit, $this->place->subtreeIds($unit))
            ->when($openOnly, fn ($query) => $query->whereIn('provider_status', ['posted', 'partly_paid']))
            ->orderByDesc('issue_date')->orderByDesc('id')->limit(300)->get();
    }

    /**
     * Invoices for one renewal request (newest first).
     *
     * @return Collection<int, ReceivedInvoice>
     */
    public function forRequest(Organization $unit, string $requestId): Collection
    {
        return $this->place->query(ReceivedInvoice::class, $unit, $this->place->subtreeIds($unit))->where('request_id', $requestId)
            ->orderByDesc('issue_date')->get();
    }

    public function find(Organization $unit, string $id): ReceivedInvoice
    {
        return $this->place->query(ReceivedInvoice::class, $unit, $this->place->subtreeIds($unit))->whereKey($id)->first()
            ?? throw ComplianceException::invoiceNotFound();
    }

    /** Whether the factory's company keeps books here (the bill button). */
    public function booksKept(Organization $factory): bool
    {
        return $this->bills->available($this->place->companyOf($factory));
    }

    /** projects.invoice for a shared factory: the copy made or brought up to date. */
    public function receive(ConnectionDelivery $delivery): ?string
    {
        $payload = $delivery->payload;
        $invoiceId = (string) ($payload['invoice_id'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        if ($invoiceId === '' || ! in_array($status, ReceivedInvoice::STATUSES, true) || ! is_array($payload['lines'] ?? null)) {
            return null;
        }
        $factory = $delivery->to;
        $int = fn (mixed $value) => is_int($value) && $value >= 0 ? $value : 0;
        $text = fn (mixed $value, int $max) => ($clean = mb_substr(trim((string) $value), 0, $max)) === '' ? null : $clean;
        $asOf = $int($payload['as_of'] ?? 0);

        $existing = ReceivedInvoice::query()->withoutGlobalScope(OrganizationScope::class)->where('connection_id', $delivery->connectionId)
            ->where('provider_invoice_id', $invoiceId)->first();
        if ($existing !== null && ($existing->message_id === $delivery->messageId || $existing->as_of > $asOf)) {
            return $existing->getKey();
        }
        // Another factory's copy is never moved: the invoice stays where it first came.
        if ($existing !== null && $existing->organization_id !== $factory->getKey()) {
            return null;
        }
        $request = RenewalRequest::query()->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $factory->getKey())
            ->where('connection_id', $delivery->connectionId)->whereKey((string) ($payload['request_id'] ?? ''))->first();
        $lines = array_values(array_map(fn ($line) => [
            'description' => mb_substr((string) ($line['description'] ?? ''), 0, 255),
            'quantity_milli' => $int($line['quantity_milli'] ?? 0),
            'unit_price_minor' => $int($line['unit_price_minor'] ?? 0),
            'net_minor' => $int($line['net_minor'] ?? 0),
            'tax_rate_bp' => $int($line['tax_rate_bp'] ?? 0),
            'tax_minor' => $int($line['tax_minor'] ?? 0),
            'total_minor' => $int($line['total_minor'] ?? 0),
        ], array_slice(array_filter($payload['lines'], 'is_array'), 0, 200)));
        $values = [
            'message_id' => $delivery->messageId,
            'as_of' => $asOf,
            'from_name' => mb_substr($delivery->fromName, 0, 190),
            'number' => $text($payload['number'] ?? null, 60),
            'issue_date' => Validity::day($payload['issue_date'] ?? null),
            'due_date' => Validity::day($payload['due_date'] ?? null),
            'currency_code' => preg_match('/^[A-Z]{3}$/', (string) ($payload['currency_code'] ?? '')) ? $payload['currency_code'] : 'XXX',
            'prices_include_tax' => (bool) ($payload['prices_include_tax'] ?? false),
            'net_minor' => $int($payload['net_minor'] ?? 0),
            'tax_minor' => $int($payload['tax_minor'] ?? 0),
            'total_minor' => $int($payload['total_minor'] ?? 0),
            'balance_minor' => $int($payload['balance_minor'] ?? 0),
            'lines' => $lines,
            'notes' => $text($payload['notes'] ?? null, 2000),
            'provider_status' => $status,
        ];

        if ($existing !== null) {
            $before = $existing->provider_status;
            $existing->forceFill([...$values, 'request_id' => $existing->request_id ?? $request?->getKey(), 'version' => $existing->version + 1])->save();
            $this->audit->record('compliance.invoice_updated', $existing, old: ['status' => $before], new: ['status' => $status, 'balance_minor' => $existing->balance_minor],
                organizationId: $factory->getKey());

            return $existing->getKey();
        }

        $invoice = ReceivedInvoice::query()->withoutGlobalScope(OrganizationScope::class)->create([
            ...$values,
            'organization_id' => $factory->getKey(),
            'connection_id' => $delivery->connectionId,
            'provider_invoice_id' => $invoiceId,
            'request_id' => $request?->getKey(),
            'version' => 1,
        ]);
        $this->audit->record('compliance.invoice_received', $invoice, new: ['from' => $delivery->fromName, 'number' => $invoice->number, 'total_minor' => $invoice->total_minor],
            organizationId: $factory->getKey());

        $company = $this->place->companyOf($factory);
        $this->notifier->notify('compliance.invoice_received', $this->recipients->holding('compliance.bills', $factory), fn (string $locale) => [
            'organization' => $factory->displayName($locale),
            'other' => $delivery->fromName,
            'number' => (string) $invoice->number,
        ], $company->partner, $company);

        return $invoice->getKey();
    }

    /** Taken into the factory company's books as a draft bill, once. */
    public function bill(Organization $unit, ReceivedInvoice $invoice, int $baseVersion, User $actor): ReceivedInvoice
    {
        $factory = Organization::query()->findOrFail($invoice->organization_id);
        $company = $this->place->companyOf($factory);
        if (! $this->bills->available($company)) {
            throw ComplianceException::booksOff();
        }

        return $this->place->transaction($unit, function () use ($invoice, $baseVersion, $actor, $factory, $company) {
            /** @var ReceivedInvoice $fresh */
            $fresh = ReceivedInvoice::inTenantOf($invoice->organization_id)->withoutGlobalScope(OrganizationScope::class)->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->bill_id !== null) {
                throw ComplianceException::invoiceBilled();
            }
            if ($fresh->provider_status === 'void') {
                throw ComplianceException::invoiceVoid();
            }
            if ($fresh->version !== $baseVersion) {
                throw ComplianceException::versionConflict();
            }
            $vendor = $this->bills->vendorForConnection($company, $fresh->connection_id, ['name' => $fresh->from_name], $actor);
            $bill = $this->bills->draftExpense($company, [
                'party_id' => $vendor,
                'issue_date' => $fresh->issue_date?->toDateString() ?? now()->toDateString(),
                'due_date' => $fresh->due_date?->toDateString(),
                'reference' => $fresh->number,
                'notes' => __('compliance::compliance.invoice_bill_note', [
                    'from' => $fresh->from_name, 'number' => (string) $fresh->number,
                    'tax' => MoneyText::format($fresh->tax_minor, $fresh->currency_code, app()->getLocale()),
                    'total' => MoneyText::format($fresh->total_minor, $fresh->currency_code, app()->getLocale()),
                ]),
                'currency_code' => $fresh->currency_code,
                'expense_key' => self::EXPENSE_KEY,
                'cost_centre_id' => $factory->getKey(),
                'lines' => array_map(fn (array $line) => [
                    'description' => $line['description'] !== '' ? $line['description'] : (string) $fresh->number,
                    'quantity_milli' => max(1, $line['quantity_milli']),
                    'unit_price_minor' => $line['unit_price_minor'],
                ], $fresh->lines),
            ], $actor);
            $fresh->forceFill(['bill_id' => $bill['id'], 'billed_by' => $actor->getKey(), 'billed_at' => now(), 'version' => $fresh->version + 1])->save();
            $this->audit->record('compliance.invoice_billed', $fresh, new: ['number' => $fresh->number, 'bill' => $bill['id'], 'total_minor' => $bill['total_minor']],
                actor: $actor, organizationId: $fresh->organization_id);

            return $fresh;
        });
    }
}
