<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Connections\Services\Connections;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Modules\Crm\Services\Quotations;
use Modules\Projects\Exceptions\ProjectsException;
use Modules\Projects\Models\IncomingRequest;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectLink;
use Throwable;

/**
 * A connected project's quotation sent to the client over the business
 * connection (optional): the quotation as CRM has it (lines, totals,
 * terms) goes to the client's factory (projects.quotation) and is marked
 * sent in CRM. The client's answer (compliance.quote_reply) is applied in
 * CRM in the sender's name: accepted (with the draft invoice when the books
 * are kept) or declined with the client's reason; the sender and the
 * project's manager are told.
 */
class QuoteExchange
{
    public const KIND = 'projects.quotation';

    public function __construct(
        private Workplace $place,
        private Quotations $quotations,
        private Connections $connections,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    public function send(Project $project, string $quoteId, User $actor): ProjectLink
    {
        if ($project->connection_id === null || $project->client_unit_id === null) {
            throw ProjectsException::notConnectedProject();
        }
        $unit = Organization::query()->findOrFail($project->organization_id);
        $company = Organization::query()->findOrFail($project->company_id);
        $link = $this->place->query(ProjectLink::class, $unit, [$unit->getKey()])->where('project_id', $project->getKey())
            ->where('kind', ProjectLink::QUOTE)->where('target_id', $quoteId)->first() ?? throw ProjectsException::quoteNotOfProject();
        $quote = $this->quotations->full($company, $quoteId) ?? throw ProjectsException::quoteNotOfProject();
        if ($quote['kind'] !== 'quotation' || ! in_array($quote['status'], ['draft', 'sent'], true)) {
            throw ProjectsException::quoteNotSendable();
        }
        $connection = $this->connections->find($project->connection_id);
        if ($connection === null || ! $connection->isActive()) {
            throw ProjectsException::connectionClosed();
        }
        $request = $project->request_id === null ? null : $this->place->query(IncomingRequest::class, $unit, [$unit->getKey()])->whereKey($project->request_id)->first();

        return $this->place->transaction($unit, function () use ($project, $unit, $company, $link, $quote, $connection, $request, $actor) {
            $this->quotations->markSent($company, $quote['id'], $actor);
            // A changed quotation (a new version) is a new message: the client's pending one is replaced.
            $this->connections->send($connection, self::KIND, $unit, $project->client_unit_id, [
                'quote_id' => $quote['id'],
                'request_id' => $request?->client_request_id,
                'number' => $quote['number'],
                'subject' => $quote['subject'],
                'issue_date' => $quote['issue_date'],
                'valid_until' => $quote['valid_until'],
                'currency_code' => $quote['currency_code'],
                'prices_include_tax' => $quote['prices_include_tax'],
                'subtotal_minor' => $quote['subtotal_minor'],
                'discount_minor' => $quote['discount_minor'],
                'tax_minor' => $quote['tax_minor'],
                'total_minor' => $quote['total_minor'],
                'lines' => $quote['lines'],
                'notes' => $quote['notes'],
                'terms' => $quote['terms'],
            ], [], "quote-{$quote['id']}-v{$quote['version']}", $actor);
            $link->forceFill(['sent_at' => now(), 'sent_by' => $actor->getKey(), 'client_answer' => null, 'client_reason' => null])->save();
            $this->audit->record('projects.quote_sent', $project, new: ['quote' => $quote['number'], 'total_minor' => $quote['total_minor']], actor: $actor, organizationId: $unit->getKey());

            return $link;
        });
    }

    /** compliance.quote_reply: only for a quote this very connection carried; applied once. */
    public function receiveReply(ConnectionDelivery $delivery): ?string
    {
        $payload = $delivery->payload;
        $status = $payload['status'] ?? null;
        if (! in_array($status, ['accepted', 'declined'], true)) {
            return null;
        }
        $link = ProjectLink::query()->withoutGlobalScope(OrganizationScope::class)->where('kind', ProjectLink::QUOTE)
            ->where('target_id', (string) ($payload['quote_id'] ?? ''))->whereNotNull('sent_at')->first();
        $project = $link === null ? null : Project::query()->withoutGlobalScope(OrganizationScope::class)->find($link->project_id);
        if ($link === null || $project === null || $project->connection_id !== $delivery->connectionId) {
            return null;
        }
        if ($link->client_answer !== null) {
            return $link->getKey();
        }
        $reason = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 500) ?: null;
        $link->forceFill(['client_answer' => $status, 'client_reason' => $reason])->save();
        $sender = User::query()->find($link->sent_by);
        $company = Organization::query()->findOrFail($project->company_id);
        if ($sender !== null) {
            try {
                $status === 'accepted'
                    ? $this->quotations->accept($company, $link->target_id, $sender)
                    : $this->quotations->decline($company, $link->target_id, $reason ?? (string) __('projects::projects.client_declined'), $sender);
            } catch (Throwable) {
                // Changed in CRM meanwhile (e.g. already decided there): the answer stays on the project to act on.
            }
        }
        $this->audit->record("projects.quote_{$status}", $project, new: ['quote' => $link->target_id, 'from' => $delivery->fromName], reason: $reason,
            organizationId: $project->organization_id);

        $people = User::query()->whereKey(array_values(array_filter(array_unique([$link->sent_by, $project->manager_id]))))->get();
        if ($people->isNotEmpty()) {
            $this->notifier->notify('projects.quote_answered', $people, fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'project' => "{$project->code} {$project->name}",
                'other' => $delivery->fromName,
                'answer' => __("projects::projects.quote_answers.{$status}", [], $locale),
            ], $company->partner, $company);
        }

        return $link->getKey();
    }
}
