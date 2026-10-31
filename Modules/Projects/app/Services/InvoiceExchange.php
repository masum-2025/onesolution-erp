<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\Services\Connections;
use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Services\Customers;
use Modules\Projects\Exceptions\ProjectsException;
use Modules\Projects\Models\IncomingRequest;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectLink;

/**
 * A connected project's invoice sent to the client over the business
 * connection (optional): the invoice as Accounting has it now (lines, VAT,
 * totals, what is still owed) goes to the client's factory
 * (projects.invoice). Only a posted invoice goes; sending it again after a
 * payment or a void brings the client's copy up to date (a void one only
 * when it was sent before). Nothing comes back.
 */
class InvoiceExchange
{
    public const KIND = 'projects.invoice';

    public function __construct(
        private Workplace $place,
        private Billing $billing,
        private Customers $customers,
        private Connections $connections,
        private AuditLogger $audit,
    ) {}

    public function send(Project $project, string $invoiceId, User $actor): ProjectLink
    {
        if ($project->connection_id === null || $project->client_unit_id === null) {
            throw ProjectsException::notConnectedProject();
        }
        $unit = Organization::query()->findOrFail($project->organization_id);
        $company = Organization::query()->findOrFail($project->company_id);
        if (! $this->billing->ownsInvoice($project, $invoiceId)) {
            throw ProjectsException::invoiceNotOfProject();
        }
        $invoice = $this->customers->invoiceFull($company, $invoiceId) ?? throw ProjectsException::invoiceNotOfProject();
        $sentBefore = $this->place->query(ProjectLink::class, $unit, [$unit->getKey()])->where('project_id', $project->getKey())
            ->where('kind', ProjectLink::INVOICE)->where('target_id', $invoiceId)->whereNotNull('sent_at')->exists();
        if (! in_array($invoice['status'], ['posted', 'partly_paid', 'paid'], true) && ! ($invoice['status'] === 'void' && $sentBefore)) {
            throw ProjectsException::invoiceNotSendable();
        }
        $connection = $this->connections->find($project->connection_id);
        if ($connection === null || ! $connection->isActive()) {
            throw ProjectsException::connectionClosed();
        }
        $request = $project->request_id === null ? null : $this->place->query(IncomingRequest::class, $unit, [$unit->getKey()])->whereKey($project->request_id)->first();

        return $this->place->transaction($unit, function () use ($project, $unit, $invoice, $connection, $request, $actor) {
            $link = $this->billing->invoiceLink($project, $invoice['id'], $actor);
            // The same state sent twice is one message; a payment or a void is a new one.
            $this->connections->send($connection, self::KIND, $unit, $project->client_unit_id, [
                'invoice_id' => $invoice['id'],
                'request_id' => $request?->client_request_id,
                'as_of' => now()->getTimestampMs(),
                'number' => $invoice['number'],
                'status' => $invoice['status'],
                'issue_date' => $invoice['issue_date'],
                'due_date' => $invoice['due_date'],
                'currency_code' => $invoice['currency_code'],
                'prices_include_tax' => $invoice['prices_include_tax'],
                'net_minor' => $invoice['net_minor'],
                'tax_minor' => $invoice['tax_minor'],
                'total_minor' => $invoice['total_minor'],
                'balance_minor' => $invoice['balance_minor'],
                'lines' => $invoice['lines'],
                'notes' => $invoice['notes'],
            ], [], "invoice-{$invoice['id']}-{$invoice['status']}-{$invoice['balance_minor']}", $actor);
            $link->forceFill(['sent_at' => now(), 'sent_by' => $actor->getKey()])->save();
            $this->audit->record('projects.invoice_sent', $project, new: ['invoice' => $invoice['number'], 'status' => $invoice['status'], 'total_minor' => $invoice['total_minor']],
                actor: $actor, organizationId: $unit->getKey());

            return $link;
        });
    }
}
