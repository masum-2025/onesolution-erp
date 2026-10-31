<?php

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Projects\Http\Controllers\Concerns\FindsWorkplace;
use Modules\Projects\Http\Requests\BillRequest;
use Modules\Projects\Http\Requests\VersionRequest;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\Billing;
use Modules\Projects\Services\InvoiceExchange;
use Modules\Projects\Services\ProjectBook;
use Modules\Projects\Services\QuoteExchange;

/**
 * A project's money (PRJ-2): its quotes (CRM) and invoices (Accounting)
 * as those modules have them. Reading needs projects.view; making a quote
 * projects.bill and crm.edit, an invoice projects.bill and accounting.sell
 * (each module checks its own rules again).
 */
class MoneyController extends Controller
{
    use FindsWorkplace;

    public function __construct(private ProjectBook $projects, private Billing $billing, private QuoteExchange $exchange, private InvoiceExchange $invoices) {}

    public function show(string $organization, string $project): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.view');
        $found = $this->projects->find($unit, $project);

        return response()->json(['data' => [...$this->billing->money($found), ...$this->abilities($found)]]);
    }

    public function quote(BillRequest $request, string $organization, string $project): JsonResponse
    {
        $found = $this->billable($organization, $project, 'crm.edit');
        $quote = $this->billing->quote($found, $request->validated('kind'), $request->validated('lines'), $request->user());

        return response()->json(['data' => $quote], 201);
    }

    public function invoice(BillRequest $request, string $organization, string $project): JsonResponse
    {
        $found = $this->billable($organization, $project, 'accounting.sell');
        $invoice = $this->billing->invoice($found, $request->validated('issue_date'), $request->validated('lines'), $request->user());

        return response()->json(['data' => $invoice], 201);
    }

    /** A quotation to the connected client over the business connection (needs crm.edit too). */
    public function sendQuote(string $organization, string $project, string $quote): JsonResponse
    {
        $found = $this->billable($organization, $project, 'crm.edit');
        $link = $this->exchange->send($found, $quote, request()->user());

        return response()->json(['data' => ['sent_at' => $link->sent_at?->toIso8601String()]]);
    }

    /** A posted invoice to the connected client over the business connection (needs accounting.sell too). */
    public function sendInvoice(string $organization, string $project, string $invoice): JsonResponse
    {
        $found = $this->billable($organization, $project, 'accounting.sell');
        $link = $this->invoices->send($found, $invoice, request()->user());

        return response()->json(['data' => ['sent_at' => $link->sent_at?->toIso8601String()]]);
    }

    /** A client typed by name becomes a CRM client (needs crm.edit too). */
    public function clientToCrm(VersionRequest $request, string $organization, string $project): JsonResponse
    {
        $found = $this->billable($organization, $project, 'crm.edit');
        $changed = $this->billing->clientToCrm($found, (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => ['client' => ['contact_id' => $changed->client_contact_id, 'name' => $changed->client_name], 'version' => $changed->version]]);
    }

    private function billable(string $organization, string $project, string $other): Project
    {
        $unit = $this->unit($organization, 'projects.view');
        $found = $this->projects->find($unit, $project);
        $this->writable($found->organization_id, 'projects.bill');
        Gate::authorize($other, Organization::query()->findOrFail($found->company_id));

        return $found;
    }

    /**
     * What the person may do here, and why not (CRM off, no books, client not in CRM).
     *
     * @return array{state: array<string, bool>, can: array<string, bool>}
     */
    private function abilities(Project $project): array
    {
        $state = $this->billing->state($project);
        $at = Organization::query()->findOrFail($project->organization_id);
        $company = Organization::query()->findOrFail($project->company_id);
        $bills = app(CurrentContext::class)->canWriteTo($at->getKey()) && Gate::allows('projects.bill', $at) && $project->status !== Project::CANCELLED;

        return [
            'state' => $state,
            'can' => [
                'quote' => $bills && $state['crm'] && $state['client_in_crm'] && Gate::allows('crm.edit', $company),
                'invoice' => $bills && $state['books'] && $state['client_in_crm'] && Gate::allows('accounting.sell', $company),
                'client_to_crm' => $bills && $state['crm'] && ! $state['client_in_crm'] && Gate::allows('crm.edit', $company),
                // Quotations go to a connected client over the business connection.
                'send_to_client' => $bills && $state['crm'] && $project->connection_id !== null && Gate::allows('crm.edit', $company),
                // And its posted invoices.
                'send_invoice' => $bills && $state['books'] && $project->connection_id !== null && Gate::allows('accounting.sell', $company),
            ],
        ];
    }
}
