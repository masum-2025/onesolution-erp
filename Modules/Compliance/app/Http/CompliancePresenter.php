<?php

namespace Modules\Compliance\Http;

use App\Platform\Connections\ConnectionKinds;
use App\Platform\Connections\Models\ConnectionMessage;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Models\AuditChange;
use Modules\Compliance\Models\ProposedResult;
use Modules\Compliance\Models\ReceivedQuote;
use Modules\Compliance\Models\RenewalRequest;
use Modules\Compliance\Services\Lists;
use Modules\Compliance\Services\Renewals;
use Modules\Compliance\Services\Results;
use Modules\Compliance\Support\Validity;

/**
 * API shapes of the register. Days left and the state are worked out here
 * from the expiry day and the company's today; names are read once per
 * request.
 */
class CompliancePresenter
{
    /** @var array<string, Organization|null> */
    private array $units = [];

    /** @var array<string, \Illuminate\Support\Collection<string, Model>> Factory id => its services by code. */
    private array $services = [];

    /** @var array<string, string|null> Connection message id => its status. */
    private array $deliveries = [];

    /**
     * Read the delivery status of many requests' messages at once (a list).
     *
     * @param  list<string>  $messageIds
     */
    public function loadDeliveries(array $messageIds): void
    {
        $found = ConnectionMessage::query()->whereKey($messageIds)->pluck('status', 'id')->all();
        foreach ($messageIds as $id) {
            $this->deliveries[$id] = $found[$id] ?? null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function line(Audit $audit, CarbonImmutable $today, int $expiringDays): array
    {
        $factory = $this->unit($audit->organization_id);

        return [
            'id' => $audit->getKey(),
            'factory' => ['id' => $audit->organization_id, 'name' => $factory?->displayName(), 'type' => $factory?->type->value, 'parent_id' => $factory?->parent_id],
            'service' => $audit->service === null ? null : ['id' => $audit->service_id, 'code' => $audit->service->code, 'name' => $audit->service->textIn('name')],
            'provider' => $audit->provider === null ? null : [
                'id' => $audit->provider_id,
                'name' => $audit->provider->name,
                'on_platform' => $audit->provider->connection_id !== null,
                // A renewal request reaches them on the platform (their side can receive it); else it is noted and emailed.
                'receives_requests' => $audit->provider->connection_id !== null && app(ConnectionKinds::class)->has(Renewals::KIND),
            ],
            'audit_type' => $audit->auditType === null ? null : ['id' => $audit->audit_type_id, 'name' => $audit->auditType->textIn('name')],
            'last_audit_on' => $audit->last_audit_on?->toDateString(),
            'expires_on' => $audit->expires_on?->toDateString(),
            'days_left' => Validity::daysLeft($audit->expires_on, $today),
            'state' => Validity::state($audit->expires_on, $today, $expiringDays),
            'result' => $audit->result,
            'certificate_no' => $audit->certificate_no,
            'notes' => $audit->notes,
            'version' => $audit->version,
            // Allowed, and written from here: a group's context reads its companies' lines, never writes them.
            'can' => $factory === null || ! app(CurrentContext::class)->canWriteTo($factory->getKey()) ? ['edit' => false, 'dates' => false, 'request' => false] : [
                'edit' => Gate::allows('compliance.manage', $factory),
                'dates' => Gate::allows('compliance.set_expiry', $factory),
                'request' => $audit->provider_id !== null && Gate::allows('compliance.request', $factory),
            ],
        ];
    }

    /**
     * A renewal request, with the provider's answer.
     *
     * @return array<string, mixed>
     */
    public function request(RenewalRequest $request): array
    {
        $factory = $this->unit($request->organization_id);
        if ($request->message_id !== null && ! array_key_exists($request->message_id, $this->deliveries)) {
            $this->loadDeliveries([$request->message_id]);
        }
        $delivery = $request->message_id === null ? null : $this->deliveries[$request->message_id];
        $writes = $factory !== null && app(CurrentContext::class)->canWriteTo($factory->getKey());

        return [
            'id' => $request->getKey(),
            'audit_id' => $request->audit_id,
            'factory' => ['id' => $request->organization_id, 'name' => $factory?->displayName()],
            'provider' => $request->provider === null ? null : ['id' => $request->provider_id, 'name' => $request->provider->name, 'email' => $request->provider->email],
            'status' => $request->status,
            'open' => $request->isOpen(),
            // Over the connection: whether it reached them (queued, delivered, failed).
            'delivery' => $delivery,
            'wanted_by' => $request->wanted_by?->toDateString(),
            'note' => $request->note,
            'planned_on' => $request->planned_on?->toDateString(),
            'reply_note' => $request->reply_note,
            'replied_at' => $request->replied_at?->toIso8601String(),
            'cancel_reason' => $request->cancel_reason,
            'created_at' => $request->created_at?->toIso8601String(),
            'version' => $request->version,
            'can' => ['cancel' => $writes && $request->isOpen() && Gate::allows('compliance.request', $factory)],
        ];
    }

    /**
     * A quotation the provider sent, line by line.
     *
     * @return array<string, mixed>
     */
    public function quote(ReceivedQuote $quote): array
    {
        $factory = $this->unit($quote->organization_id);
        $writes = $factory !== null && app(CurrentContext::class)->canWriteTo($factory->getKey());

        return [
            'id' => $quote->getKey(),
            'factory' => ['id' => $quote->organization_id, 'name' => $factory?->displayName()],
            'request_id' => $quote->request_id,
            'from' => $quote->from_name,
            'number' => $quote->number,
            'subject' => $quote->subject,
            'issue_date' => $quote->issue_date?->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'currency_code' => $quote->currency_code,
            'prices_include_tax' => $quote->prices_include_tax,
            'subtotal_minor' => $quote->subtotal_minor,
            'discount_minor' => $quote->discount_minor,
            'tax_minor' => $quote->tax_minor,
            'total_minor' => $quote->total_minor,
            'lines' => $quote->lines,
            'notes' => $quote->notes,
            'terms' => $quote->terms,
            'status' => $quote->status,
            'reason' => $quote->reason,
            'decided_at' => $quote->decided_at?->toIso8601String(),
            'created_at' => $quote->created_at?->toIso8601String(),
            'version' => $quote->version,
            'can' => ['decide' => $writes && $quote->status === ReceivedQuote::PENDING && Gate::allows('compliance.quotes', $factory)],
        ];
    }

    /**
     * A result the provider sent, with links to its files.
     *
     * @return array<string, mixed>
     */
    public function result(ProposedResult $result, ?Audit $audit): array
    {
        $factory = $this->unit($result->organization_id);
        $results = app(Results::class);
        $writes = $factory !== null && app(CurrentContext::class)->canWriteTo($factory->getKey());

        return [
            'id' => $result->getKey(),
            'audit_id' => $result->audit_id,
            'factory' => ['id' => $result->organization_id, 'name' => $factory?->displayName()],
            'from' => $result->from_name,
            // The client's own name of the service (its lists), else what the provider called it.
            'service' => ['code' => $result->service_code, 'name' => $audit?->service?->textIn('name') ?? $this->serviceName($result) ?? $result->service_name ?? $result->service_code],
            'last_audit_on' => $result->last_audit_on?->toDateString(),
            'expires_on' => $result->expires_on?->toDateString(),
            'result' => $result->result,
            'certificate_no' => $result->certificate_no,
            'note' => $result->note,
            // What the register line says now, to compare before accepting.
            'current' => $audit === null ? null : [
                'last_audit_on' => $audit->last_audit_on?->toDateString(),
                'expires_on' => $audit->expires_on?->toDateString(),
                'result' => $audit->result,
                'certificate_no' => $audit->certificate_no,
            ],
            'files' => collect((array) $result->files)->values()->map(fn (array $file, int $index) => [
                'name' => $file['name'] ?? '',
                'size' => (int) ($file['size'] ?? 0),
                'url' => $results->link($result, $index),
            ])->all(),
            'status' => $result->status,
            'reject_reason' => $result->reject_reason,
            'decided_at' => $result->decided_at?->toIso8601String(),
            'created_at' => $result->created_at?->toIso8601String(),
            'version' => $result->version,
            'can' => ['decide' => $writes && $result->status === ProposedResult::PENDING && Gate::allows('compliance.confirm', $factory)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function change(AuditChange $change, array $users): array
    {
        return [
            'kind' => $change->kind,
            'old' => $change->old,
            'new' => $change->new,
            'reason' => $change->reason,
            'by' => $users[$change->user_id] ?? null,
            'at' => $change->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function item(string $kind, Model $item, Organization $at): array
    {
        $own = $item->organization_id === $at->getKey();
        $base = ['id' => $item->getKey(), 'is_active' => (bool) $item->is_active, 'version' => $item->version, 'own' => $own, 'kept_by' => $own ? null : $this->unit($item->organization_id)?->displayName()];

        return $kind === 'providers'
            ? [...$base, 'name' => $item->name, 'email' => $item->email, 'phone' => $item->phone, 'on_platform' => $item->connection_id !== null, 'connection_id' => $item->connection_id]
            : [...$base, 'code' => $item->code, 'name' => $item->textIn('name'), 'names' => $item->texts('name'), 'sort_order' => $item->sort_order,
                ...($kind === 'services' ? ['validity_months' => $item->validity_months] : [])];
    }

    /** A result's service by its code in the lists usable at its factory (read once per factory). */
    private function serviceName(ProposedResult $result): ?string
    {
        $factory = $this->unit($result->organization_id);
        if ($factory === null) {
            return null;
        }
        $this->services[$factory->getKey()] ??= app(Lists::class)->of($factory, 'services', all: true)->keyBy('code');

        return $this->services[$factory->getKey()]->get($result->service_code)?->textIn('name');
    }

    private function unit(string $id): ?Organization
    {
        if (! array_key_exists($id, $this->units)) {
            $this->units[$id] = Organization::query()->find($id);
        }

        return $this->units[$id];
    }
}
