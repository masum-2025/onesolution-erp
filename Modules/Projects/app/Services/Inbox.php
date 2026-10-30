<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Models\Connection;
use App\Platform\Connections\Services\Connections;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Projects\Exceptions\ProjectsException;
use Modules\Projects\Models\IncomingRequest;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\Template;

/**
 * Renewal requests from connected clients (optional: only over a business
 * connection). What a client's Compliance sends arrives as a new request;
 * accepting it opens a project for that client and factory (its template
 * found by the service's code when one matches) and tells the client the
 * planned day; declining tells the reason. When the work is done the
 * project sends the result (dates, grade, certificate file) back, which the
 * client accepts into its register. Projects without a connection never
 * touch any of this.
 */
class Inbox
{
    public const REPLY = 'projects.request_reply';

    public const RESULT = 'projects.audit_result';

    public function __construct(
        private Workplace $place,
        private ProjectBook $projects,
        private Templates $templates,
        private Clients $clients,
        private Connections $connections,
        private AuditLogger $audit,
    ) {}

    /** compliance.renewal_request arrived for the provider company; once per message. */
    public function receive(ConnectionDelivery $delivery): ?string
    {
        $existing = IncomingRequest::query()->withoutGlobalScope(OrganizationScope::class)->where('message_id', $delivery->messageId)->first();
        if ($existing !== null) {
            return $existing->getKey();
        }
        $payload = $delivery->payload;
        $clientRequestId = (string) ($payload['request_id'] ?? '');
        if (! Str::isUlid($clientRequestId)) {
            return null;
        }
        $connection = $this->connections->find($delivery->connectionId);
        $text = fn (mixed $value, int $max) => ($clean = mb_substr(trim((string) $value), 0, $max)) === '' ? null : $clean;
        $day = fn (mixed $value) => self::day($value);

        $request = IncomingRequest::query()->withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $delivery->to->getKey(),
            'connection_id' => $delivery->connectionId,
            'client_request_id' => $clientRequestId,
            'message_id' => $delivery->messageId,
            'client_unit_id' => $delivery->fromUnitId,
            'client_name' => mb_substr((string) ($connection?->client?->displayName() ?? $delivery->fromName), 0, 190),
            'factory_name' => mb_substr($delivery->fromName, 0, 190),
            'service_code' => $text($payload['service']['code'] ?? null, 30),
            'service_names' => array_map(fn ($name) => mb_substr((string) $name, 0, 190), array_filter((array) ($payload['service']['names'] ?? []), 'is_string')) ?: null,
            'expires_on' => $day($payload['expires_on'] ?? null),
            'wanted_by' => $day($payload['wanted_by'] ?? null),
            'note' => $text($payload['note'] ?? null, 1000),
            'requested_by' => $text($payload['requested_by'] ?? null, 190),
            'status' => IncomingRequest::NEW,
            'version' => 1,
        ]);
        $this->audit->record('projects.request_received', $request, new: ['from' => $delivery->fromName, 'service' => $request->service_code],
            organizationId: $delivery->to->getKey());

        return $request->getKey();
    }

    /** compliance.renewal_cancelled: the client took it back (a project already open stays). */
    public function receiveCancel(ConnectionDelivery $delivery): ?string
    {
        $request = IncomingRequest::query()->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $delivery->to->getKey())
            ->where('connection_id', $delivery->connectionId)->where('client_request_id', (string) ($delivery->payload['request_id'] ?? ''))->first();
        if ($request === null || $request->status === IncomingRequest::CANCELLED) {
            return $request?->getKey();
        }
        $old = $request->status;
        $request->forceFill([
            'status' => $old === IncomingRequest::NEW ? IncomingRequest::CANCELLED : $old,
            'reason' => mb_substr(trim((string) ($delivery->payload['reason'] ?? '')), 0, 500) ?: null,
            'version' => $request->version + 1,
        ])->save();
        $this->audit->record('projects.request_cancelled', $request, old: ['status' => $old], new: ['status' => $request->status], organizationId: $request->organization_id);

        return $request->getKey();
    }

    /**
     * @return Collection<int, IncomingRequest>
     */
    public function list(Organization $unit, bool $newOnly = false): Collection
    {
        return $this->place->query(IncomingRequest::class, $unit, $this->place->subtreeIds($unit))
            ->when($newOnly, fn ($query) => $query->where('status', IncomingRequest::NEW))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(300)->get();
    }

    public function find(Organization $unit, string $id): IncomingRequest
    {
        return $this->place->query(IncomingRequest::class, $unit, $this->place->subtreeIds($unit))->whereKey($id)->first()
            ?? throw ProjectsException::requestNotFound();
    }

    /** The template whose service is the request's (codes compared without case), if any. */
    public function templateFor(Organization $unit, IncomingRequest $request): ?Template
    {
        if ($request->service_code === null) {
            return null;
        }

        return $this->templates->usableAt($unit)->first(fn (Template $template) => strcasecmp((string) $template->service, $request->service_code) === 0);
    }

    /**
     * Open the project and tell the client its planned day.
     *
     * @param  array{name?: string|null, template_id?: string|null, manager_id?: string|null, starts_on: string, due_on?: string|null}  $data
     */
    public function accept(IncomingRequest $request, array $data, User $actor): Project
    {
        $unit = Organization::query()->findOrFail($request->organization_id);
        $this->assertNew($request);
        $connection = $this->activeConnection($request);
        $company = $this->place->companyOf($unit);
        $templateId = $data['template_id'] ?? $this->templateFor($unit, $request)?->getKey();
        // The client once in CRM: found by its exact name, not added again.
        $known = $this->clients->usesCrm($company)
            ? collect($this->clients->search($company, $request->client_name))->first(fn (array $client) => mb_strtolower($client['name']) === mb_strtolower($request->client_name))
            : null;

        return $this->place->transaction($unit, function () use ($request, $unit, $connection, $data, $templateId, $known, $actor) {
            $fresh = $this->locked($request);
            $this->assertNew($fresh);
            $project = $this->projects->open($unit, [
                'name' => $data['name'] ?? trim(($fresh->serviceName() ?: $fresh->service_code).' · '.$fresh->factory_name),
                'client_contact_id' => $known['id'] ?? null,
                'client_name' => $known === null ? $fresh->client_name : null,
                'template_id' => $templateId,
                'service' => $fresh->service_code,
                'manager_id' => $data['manager_id'] ?? null,
                'starts_on' => $data['starts_on'],
                'due_on' => $data['due_on'] ?? $fresh->wanted_by?->toDateString(),
                'description' => $fresh->note,
            ], $actor);
            $project->forceFill(['connection_id' => $fresh->connection_id, 'client_unit_id' => $fresh->client_unit_id, 'request_id' => $fresh->getKey()])->save();
            $fresh->forceFill(['status' => IncomingRequest::ACCEPTED, 'project_id' => $project->getKey(), 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'version' => $fresh->version + 1])->save();

            $this->connections->send($connection, self::REPLY, $unit, $fresh->client_unit_id,
                ['request_id' => $fresh->client_request_id, 'status' => 'accepted', 'planned_on' => $data['starts_on'], 'note' => $project->code],
                [], 'reply-accepted-'.$fresh->getKey(), $actor);
            $this->audit->record('projects.request_accepted', $fresh, new: ['project_id' => $project->getKey()], actor: $actor, organizationId: $unit->getKey());

            return $project;
        });
    }

    public function decline(IncomingRequest $request, string $reason, User $actor): IncomingRequest
    {
        $unit = Organization::query()->findOrFail($request->organization_id);
        $this->assertNew($request);
        $connection = $this->activeConnection($request);

        return $this->place->transaction($unit, function () use ($request, $unit, $connection, $reason, $actor) {
            $fresh = $this->locked($request);
            $this->assertNew($fresh);
            $fresh->forceFill(['status' => IncomingRequest::DECLINED, 'reason' => $reason, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'version' => $fresh->version + 1])->save();
            $this->connections->send($connection, self::REPLY, $unit, $fresh->client_unit_id,
                ['request_id' => $fresh->client_request_id, 'status' => 'declined', 'note' => $reason], [], 'reply-declined-'.$fresh->getKey(), $actor);
            $this->audit->record('projects.request_declined', $fresh, reason: $reason, actor: $actor, organizationId: $unit->getKey());

            return $fresh;
        });
    }

    /**
     * The audit's result to the client's factory (its register takes it when the
     * client accepts it), with the certificate file; and the request done.
     *
     * @param  array{last_audit_on?: string|null, expires_on?: string|null, result?: string|null, certificate_no?: string|null, audit_type?: string|null, note?: string|null}  $data
     */
    public function sendResult(Project $project, array $data, ?UploadedFile $file, User $actor): void
    {
        if ($project->connection_id === null || $project->client_unit_id === null) {
            throw ProjectsException::notConnectedProject();
        }
        $unit = Organization::query()->findOrFail($project->organization_id);
        $request = $project->request_id === null ? null : $this->place->query(IncomingRequest::class, $unit, [$unit->getKey()])->whereKey($project->request_id)->first();
        $connection = $this->connections->find($project->connection_id);
        if ($connection === null || ! $connection->isActive()) {
            throw ProjectsException::connectionClosed();
        }
        $files = [];
        if ($file !== null) {
            $name = (Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'certificate').'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $path = $file->storeAs("projects/results/{$project->getKey()}", Str::ulid().'-'.$name, 'local');
            $files[] = ['path' => $path, 'name' => $name, 'mime' => (string) $file->getMimeType()];
        }
        $opId = 'result-'.Str::ulid();
        $this->connections->send($connection, self::RESULT, $unit, $project->client_unit_id, [
            'request_id' => $request?->client_request_id,
            'service' => ['code' => $request?->service_code ?? $project->service, 'name' => $request?->serviceName('en') ?: $project->service],
            'audit_type' => $data['audit_type'] ?? null,
            'last_audit_on' => $data['last_audit_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'result' => $data['result'] ?? null,
            'certificate_no' => $data['certificate_no'] ?? null,
            'note' => $data['note'] ?? null,
        ], $files, $opId, $actor);
        if ($request !== null) {
            $this->connections->send($connection, self::REPLY, $unit, $project->client_unit_id,
                ['request_id' => $request->client_request_id, 'status' => 'done', 'note' => $project->code], [], 'reply-done-'.$opId, $actor);
        }
        $this->audit->record('projects.result_sent', $project, new: [...array_intersect_key($data, array_flip(['last_audit_on', 'expires_on', 'result', 'certificate_no'])), 'files' => count($files)],
            actor: $actor, organizationId: $unit->getKey());
    }

    /** A plain day (Y-m-d) from the other side's message, or null when it is not a real day. */
    private static function day(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')->toDateString() === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertNew(IncomingRequest $request): void
    {
        if ($request->status !== IncomingRequest::NEW) {
            throw ProjectsException::requestClosed();
        }
    }

    private function activeConnection(IncomingRequest $request): Connection
    {
        $connection = $this->connections->find($request->connection_id);
        if ($connection === null || ! $connection->isActive()) {
            throw ProjectsException::connectionClosed();
        }

        return $connection;
    }

    private function locked(IncomingRequest $request): IncomingRequest
    {
        return IncomingRequest::inTenantOf($request->organization_id)->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
    }
}
