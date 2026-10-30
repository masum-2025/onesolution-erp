<?php

namespace Modules\Compliance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\ConnectionKinds;
use App\Platform\Connections\Exceptions\ConnectionException;
use App\Platform\Connections\Services\Connections;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Collection;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Models\RenewalRequest;
use Modules\Compliance\Support\Validity;

/**
 * Renewal requests (CMP-2): a register line's provider is asked to renew
 * it. A provider connected on the platform gets it over the business
 * connection (kind compliance.renewal_request, received by its Projects)
 * and answers back (projects.request_reply: accepted with a planned day,
 * declined, done); any other provider gets it from the client by email or
 * phone, and the request is kept as a record. One open request per line.
 */
class Renewals
{
    public const KIND = 'compliance.renewal_request';

    public const CANCELLED_KIND = 'compliance.renewal_cancelled';

    /** What the provider may answer (projects.request_reply). */
    public const REPLIES = [RenewalRequest::ACCEPTED, RenewalRequest::DECLINED, RenewalRequest::DONE];

    public function __construct(
        private Place $place,
        private Connections $connections,
        private ConnectionKinds $kinds,
        private AuditLogger $audit,
        private Recipients $recipients,
        private Notifier $notifier,
    ) {}

    /**
     * The latest request of each line (open or not), by audit id.
     *
     * @param  list<string>  $auditIds
     * @return Collection<string, RenewalRequest>
     */
    public function latestOf(Organization $unit, array $auditIds): Collection
    {
        if ($auditIds === []) {
            return collect();
        }

        return $this->place->query(RenewalRequest::class, $unit, $this->place->subtreeIds($unit))->whereIn('audit_id', $auditIds)
            ->orderByDesc('created_at')->orderByDesc('id')->get()->unique('audit_id')->keyBy('audit_id');
    }

    /**
     * Requests at the unit and below, newest first.
     *
     * @return Collection<int, RenewalRequest>
     */
    public function list(Organization $unit, bool $openOnly = false, int $limit = 300): Collection
    {
        return $this->place->query(RenewalRequest::class, $unit, $this->place->subtreeIds($unit))
            ->with(['provider' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)])
            ->when($openOnly, fn ($query) => $query->whereIn('status', RenewalRequest::OPEN))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }

    public function find(Organization $unit, string $id): RenewalRequest
    {
        return $this->place->query(RenewalRequest::class, $unit, $this->place->subtreeIds($unit))->whereKey($id)->first()
            ?? throw ComplianceException::requestNotFound();
    }

    /**
     * Ask the line's provider to renew it: sent over the connection when the
     * provider is connected and the factory is shared with it, else recorded.
     */
    public function request(Audit $audit, ?string $wantedBy, ?string $note, ?User $actor): RenewalRequest
    {
        $factory = Organization::query()->findOrFail($audit->organization_id);
        $audit->loadMissing([
            'service' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class),
            'provider' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class),
            'auditType' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class),
        ]);
        $provider = $audit->provider ?? throw ComplianceException::noProvider();
        // Over the connection only when the platform can deliver it (the provider's Projects
        // receives it); otherwise the request is noted and emailed, as for any provider.
        $connection = $provider->connection_id === null || ! $this->kinds->has(self::KIND) ? null : $this->connections->find($provider->connection_id);
        if ($connection !== null) {
            // Connected: it must reach them, so say now why it cannot.
            if (! $connection->isActive()) {
                throw ConnectionException::notActive();
            }
            if (! $this->connections->covers($connection, $factory->getKey())) {
                throw ConnectionException::unitNotCovered();
            }
        }

        return $this->place->transaction($factory, function () use ($audit, $factory, $provider, $connection, $wantedBy, $note, $actor) {
            $open = $this->place->query(RenewalRequest::class, $factory, [$factory->getKey()])->where('audit_id', $audit->getKey())
                ->whereIn('status', RenewalRequest::OPEN)->lockForUpdate()->exists();
            if ($open) {
                throw ComplianceException::requestOpen();
            }
            $request = new RenewalRequest([
                'organization_id' => $factory->getKey(),
                'company_id' => $audit->company_id,
                'audit_id' => $audit->getKey(),
                'provider_id' => $provider->getKey(),
                'connection_id' => $connection?->getKey(),
                'status' => $connection === null ? RenewalRequest::RECORDED : RenewalRequest::SENT,
                'wanted_by' => $wantedBy,
                'note' => $note,
                'requested_by' => $actor?->getKey(),
                'version' => 1,
            ]);
            $request->save();

            if ($connection !== null) {
                $message = $this->connections->send($connection, self::KIND, $factory, $connection->provider_id, [
                    'request_id' => $request->getKey(),
                    'service' => ['code' => $audit->service?->code, 'names' => $audit->service?->texts('name')],
                    'audit_type' => $audit->auditType?->code,
                    'last_audit_on' => $audit->last_audit_on?->toDateString(),
                    'expires_on' => $audit->expires_on?->toDateString(),
                    'result' => $audit->result,
                    'certificate_no' => $audit->certificate_no,
                    'wanted_by' => $wantedBy,
                    'note' => $note,
                    'requested_by' => $actor?->name,
                ], [], 'renewal-'.$request->getKey(), $actor);
                $request->forceFill(['message_id' => $message->getKey()])->save();
            }

            $this->audit->record('compliance.renewal_requested', $request, new: [
                'audit_id' => $audit->getKey(), 'provider' => $provider->name, 'status' => $request->status, 'wanted_by' => $wantedBy,
            ], actor: $actor, organizationId: $factory->getKey());

            return $request;
        });
    }

    /** The client takes it back; a connected provider is told (when its side can hear it). */
    public function cancel(Organization $unit, RenewalRequest $request, int $baseVersion, ?string $reason, User $actor): RenewalRequest
    {
        return $this->place->transaction($unit, function () use ($request, $baseVersion, $reason, $actor) {
            $request = $this->locked($request, $baseVersion);
            if (! $request->isOpen()) {
                throw ComplianceException::requestClosed();
            }
            $old = $request->status;
            $request->forceFill(['status' => RenewalRequest::CANCELLED, 'cancel_reason' => $reason, 'version' => $request->version + 1])->save();

            $connection = $request->connection_id === null ? null : $this->connections->find($request->connection_id);
            if ($connection !== null && $connection->isActive() && $this->kinds->has(self::CANCELLED_KIND)) {
                $this->connections->send($connection, self::CANCELLED_KIND, Organization::query()->findOrFail($request->organization_id), $connection->provider_id,
                    ['request_id' => $request->getKey(), 'reason' => $reason], [], 'renewal-cancel-'.$request->getKey(), $actor);
            }
            $this->audit->record('compliance.renewal_cancelled', $request, old: ['status' => $old], new: ['status' => RenewalRequest::CANCELLED],
                reason: $reason, actor: $actor, organizationId: $request->organization_id);

            return $request;
        });
    }

    /**
     * The provider's answer (projects.request_reply): only for a request sent
     * over this very connection to this factory; each message counts once.
     */
    public function receiveReply(ConnectionDelivery $delivery): ?string
    {
        $payload = $delivery->payload;
        $status = $payload['status'] ?? null;
        $request = RenewalRequest::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $delivery->to->getKey())->where('connection_id', $delivery->connectionId)
            ->whereKey((string) ($payload['request_id'] ?? ''))->first();
        if ($request === null || ! in_array($status, self::REPLIES, true)) {
            return null;
        }
        if ($request->last_reply_id === $delivery->messageId || in_array($request->status, [RenewalRequest::CANCELLED, RenewalRequest::DONE], true)) {
            return $request->getKey();
        }
        $plannedOn = Validity::day($payload['planned_on'] ?? null);
        $old = ['status' => $request->status, 'planned_on' => $request->planned_on?->toDateString()];
        $request->forceFill([
            'status' => $status,
            'planned_on' => $plannedOn ?? $request->planned_on?->toDateString(),
            'reply_note' => mb_substr(trim((string) ($payload['note'] ?? '')), 0, 1000) ?: null,
            'replied_at' => now(),
            'last_reply_id' => $delivery->messageId,
            'version' => $request->version + 1,
        ])->save();
        $this->audit->record('compliance.renewal_answered', $request, old: $old, new: ['status' => $status, 'planned_on' => $plannedOn, 'from' => $delivery->fromName],
            organizationId: $request->organization_id);

        $factory = $delivery->to;
        $company = $this->place->companyOf($factory);
        $service = Audit::query()->withoutGlobalScope(OrganizationScope::class)->with(['service' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)])
            ->find($request->audit_id)?->service;
        $this->notifier->notify('compliance.renewal_answered', $this->recipients->holding('compliance.request', $factory), fn (string $locale) => [
            'organization' => $factory->displayName($locale),
            'other' => $delivery->fromName,
            'service' => (string) $service?->textIn('name', $locale),
            'status' => __("compliance::compliance.renewal_statuses.{$status}", [], $locale),
            'planned' => $request->planned_on?->locale($locale)->isoFormat('LL') ?? __('compliance::compliance.renewal.none', [], $locale),
        ], $company->partner, $company);

        return $request->getKey();
    }

    /**
     * Subject and text for a provider not on the platform, in the person's language.
     *
     * @return array{subject: string, body: string, email: string|null}
     */
    public function mail(RenewalRequest $request, Audit $audit, ?User $actor): array
    {
        $factory = Organization::query()->find($request->organization_id);
        $service = $audit->service?->textIn('name') ?? '';
        $none = __('compliance::compliance.renewal.none');
        $values = [
            'service' => $service,
            'factory' => (string) $factory?->displayName(),
            'provider' => (string) $request->provider?->name,
            'expires' => $audit->expires_on?->toDateString() ?? $none,
            'wanted' => $request->wanted_by?->toDateString() ?? $none,
            'note' => (string) $request->note,
            'name' => (string) $actor?->name,
        ];

        return [
            'subject' => __('compliance::compliance.renewal.mail_subject', $values),
            'body' => trim(__('compliance::compliance.renewal.mail_body', $values)),
            'email' => $request->provider?->email,
        ];
    }

    private function locked(RenewalRequest $request, int $baseVersion): RenewalRequest
    {
        /** @var RenewalRequest $fresh */
        $fresh = RenewalRequest::inTenantOf($request->organization_id)->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw ComplianceException::versionConflict();
        }

        return $fresh;
    }
}
