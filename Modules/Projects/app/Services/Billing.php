<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Collection;
use Modules\Accounting\Services\Customers;
use Modules\Crm\Services\Organizations;
use Modules\Crm\Services\Quotations;
use Modules\Projects\Exceptions\ProjectsException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectLink;

/**
 * A project's money (PRJ-2), kept by the modules that keep money: its
 * estimates and quotations are CRM's (numbers, VAT, sending, accepting;
 * accepting a quotation may make the invoice), its invoices Accounting's
 * (posted to the income account mapped to projects.services). Projects
 * keeps only which ones belong to it (prj_project_links) and reads their
 * numbers, statuses and amounts from those modules each time.
 */
class Billing
{
    public const INCOME_KEY = 'projects.services';

    public function __construct(
        private Workplace $place,
        private Quotations $quotations,
        private Customers $customers,
        private Organizations $organizations,
        private AuditLogger $audit,
    ) {}

    /**
     * What can be done here: CRM on (quotes), books kept (invoices), the client in CRM.
     *
     * @return array{crm: bool, books: bool, client_in_crm: bool}
     */
    public function state(Project $project): array
    {
        $company = $this->companyOf($project);

        return [
            'crm' => $this->quotations->available($company),
            'books' => $this->customers->available($company),
            'client_in_crm' => $project->client_contact_id !== null,
        ];
    }

    /**
     * The project's quotes and invoices as their modules have them now, and the totals
     * (accepted quotations; invoices not void and not draft; what is still owed).
     *
     * @return array{quotes: list<array<string, mixed>>, invoices: list<array<string, mixed>>, totals: array{quoted: int, invoiced: int, outstanding: int, currency_code: string|null}}
     */
    public function money(Project $project): array
    {
        $company = $this->companyOf($project);
        $links = $this->links($project);
        $quotes = $this->quotations->many($company, $links->where('kind', ProjectLink::QUOTE)->pluck('target_id')->values()->all());
        // An accepted quotation's invoice is the project's too.
        $invoiceIds = array_values(array_unique([
            ...$links->where('kind', ProjectLink::INVOICE)->pluck('target_id')->all(),
            ...array_filter(array_column($quotes, 'invoice_id')),
        ]));
        $invoices = $this->customers->invoices($company, $invoiceIds);
        $counted = array_filter($invoices, fn (array $invoice) => ! in_array($invoice['status'], ['void', 'draft', 'rejected', 'pending_approval'], true));

        return [
            'quotes' => array_values($quotes),
            'invoices' => array_values($invoices),
            'totals' => [
                'quoted' => array_sum(array_column(array_filter($quotes, fn (array $quote) => $quote['kind'] === 'quotation' && $quote['status'] === 'accepted'), 'total_minor')),
                'invoiced' => array_sum(array_column($counted, 'total_minor')),
                'outstanding' => array_sum(array_column($counted, 'balance_minor')),
                'currency_code' => ($quotes ? reset($quotes)['currency_code'] : null) ?? ($invoices ? reset($invoices)['currency_code'] : null),
            ],
        ];
    }

    /**
     * A draft estimate or quotation in CRM for the project's client.
     *
     * @param  'estimate'|'quotation'  $kind
     * @param  list<array{description: string, quantity_milli: int, unit_price_minor: int, discount_minor?: int, tax_code_id?: string|null}>  $lines
     * @return array<string, mixed>
     */
    public function quote(Project $project, string $kind, array $lines, User $actor): array
    {
        $company = $this->companyOf($project);
        $this->assertBillable($project);
        if (! $this->quotations->available($company)) {
            throw ProjectsException::crmOff();
        }

        return $this->place->transaction(Organization::query()->findOrFail($project->organization_id), function () use ($project, $company, $kind, $lines, $actor) {
            $quote = $this->quotations->draft($company, $project->organization_id, $project->client_contact_id, $kind,
                "{$project->code} {$project->name}", $lines, $actor, $project->service);
            $this->link($project, ProjectLink::QUOTE, $quote['id'], $actor);

            return $quote;
        });
    }

    /**
     * A draft invoice in Accounting for the project's client (its customer is
     * the CRM contact's), on the income account of projects.services.
     *
     * @param  list<array{description: string, quantity_milli: int, unit_price_minor: int, tax_code_id?: string|null}>  $lines
     * @return array{id: string, status: string, total_minor: int}
     */
    public function invoice(Project $project, string $issueDate, array $lines, User $actor): array
    {
        $company = $this->companyOf($project);
        $this->assertBillable($project);
        if (! $this->customers->available($company)) {
            throw ProjectsException::booksOff();
        }
        $contact = $this->organizations->many($company, [$project->client_contact_id])[$project->client_contact_id] ?? ['name' => $project->client_name];

        return $this->place->transaction(Organization::query()->findOrFail($project->organization_id), function () use ($project, $company, $contact, $issueDate, $lines, $actor) {
            $party = $this->customers->forContact($company, $project->client_contact_id, [
                'name' => $contact['name'], 'phone' => $contact['phone'] ?? null, 'email' => $contact['email'] ?? null,
            ], $actor);
            $invoice = $this->customers->draftInvoice($company, [
                'party_id' => $party,
                'issue_date' => $issueDate,
                'reference' => $project->code,
                'notes' => mb_substr("{$project->code} {$project->name}", 0, 1000),
                'income_key' => self::INCOME_KEY,
                'cost_centre_id' => $project->organization_id,
                'lines' => $lines,
            ], $actor);
            $this->link($project, ProjectLink::INVOICE, $invoice['id'], $actor);

            return $invoice;
        });
    }

    /** A client typed in by name becomes a CRM client, so it can be quoted and invoiced. */
    public function clientToCrm(Project $project, int $baseVersion, User $actor): Project
    {
        $company = $this->companyOf($project);
        if (! $this->organizations->available($company)) {
            throw ProjectsException::crmOff();
        }
        $unit = Organization::query()->findOrFail($project->organization_id);

        return $this->place->transaction($unit, function () use ($project, $company, $unit, $baseVersion, $actor) {
            $fresh = $this->place->query(Project::class, $unit, [$unit->getKey()])->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->version !== $baseVersion) {
                throw ProjectsException::versionConflict();
            }
            if ($fresh->client_contact_id !== null) {
                return $fresh;
            }
            $added = $this->organizations->add($company, $unit->getKey(), Clients::TAG, ['name' => (string) $fresh->client_name], $actor);
            $fresh->forceFill(['client_contact_id' => $added['id'], 'client_name' => $added['name'], 'version' => $fresh->version + 1])->save();
            $this->audit->record('projects.client_to_crm', $fresh, new: ['client_contact_id' => $added['id']], actor: $actor, organizationId: $unit->getKey());

            return $fresh;
        });
    }

    private function assertBillable(Project $project): void
    {
        if ($project->status === Project::CANCELLED) {
            throw ProjectsException::projectClosed();
        }
        if ($project->client_contact_id === null) {
            throw ProjectsException::clientNotInCrm();
        }
    }

    private function link(Project $project, string $kind, string $targetId, User $actor): void
    {
        ProjectLink::inTenantOf($project->organization_id)->withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->getKey(),
            'kind' => $kind,
            'target_id' => $targetId,
            'created_by' => $actor->getKey(),
            'created_at' => now(),
        ]);
        $this->audit->record("projects.{$kind}_linked", $project, new: ['kind' => $kind, 'id' => $targetId], actor: $actor, organizationId: $project->organization_id);
    }

    /**
     * @return Collection<int, ProjectLink>
     */
    private function links(Project $project): Collection
    {
        return ProjectLink::inTenantOf($project->organization_id)->withoutGlobalScope(OrganizationScope::class)
            ->where('project_id', $project->getKey())->orderBy('created_at')->get();
    }

    private function companyOf(Project $project): Organization
    {
        return Organization::query()->findOrFail($project->company_id);
    }
}
