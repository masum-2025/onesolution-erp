<?php

namespace Modules\Compliance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Models\ProposedResult;
use Modules\Compliance\Models\RenewalRequest;
use Modules\Compliance\Support\Validity;

/**
 * Audit results a connected provider sends (projects.audit_result, CMP-2):
 * kept as proposed until someone of the client holding compliance.confirm
 * accepts it into the register (official dates, result, certificate; the
 * line is added when missing) or rejects it with a reason. The rule
 * compliance.auto_apply_results lets them in at once instead.
 */
class Results
{
    public const KIND = 'projects.audit_result';

    /** Minutes a certificate link stays valid. */
    private const LINK_MINUTES = 10;

    public function __construct(
        private Place $place,
        private Lists $lists,
        private Register $register,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Recipients $recipients,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, ProposedResult>
     */
    public function list(Organization $unit, bool $pendingOnly = false, int $limit = 300): Collection
    {
        return $this->place->query(ProposedResult::class, $unit, $this->place->subtreeIds($unit))
            ->when($pendingOnly, fn ($query) => $query->where('status', ProposedResult::PENDING))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Results waiting for one register line.
     *
     * @return Collection<int, ProposedResult>
     */
    public function pendingOf(Organization $unit, Audit $audit): Collection
    {
        return $this->place->query(ProposedResult::class, $unit, [$audit->organization_id])->where('audit_id', $audit->getKey())
            ->where('status', ProposedResult::PENDING)->orderByDesc('created_at')->get();
    }

    /**
     * Results waiting, counted by audit id.
     *
     * @param  list<string>  $auditIds
     * @return array<string, int>
     */
    public function pendingByAudit(Organization $unit, array $auditIds): array
    {
        if ($auditIds === []) {
            return [];
        }

        return $this->place->query(ProposedResult::class, $unit, $this->place->subtreeIds($unit))->whereIn('audit_id', $auditIds)
            ->where('status', ProposedResult::PENDING)->pluck('audit_id')->countBy()->all();
    }

    public function find(Organization $unit, string $id): ProposedResult
    {
        return $this->place->query(ProposedResult::class, $unit, $this->place->subtreeIds($unit))->whereKey($id)->first()
            ?? throw ComplianceException::resultNotFound();
    }

    /** Store what arrived for a shared factory; once per message. */
    public function receive(ConnectionDelivery $delivery): ?string
    {
        $existing = ProposedResult::query()->withoutGlobalScope(OrganizationScope::class)->where('message_id', $delivery->messageId)->first();
        if ($existing !== null) {
            return $existing->getKey();
        }
        $factory = $delivery->to;
        $payload = $delivery->payload;
        $code = mb_substr(trim((string) ($payload['service']['code'] ?? '')), 0, 30);
        if ($code === '') {
            return null;
        }
        $last = Validity::day($payload['last_audit_on'] ?? null);
        $expires = Validity::day($payload['expires_on'] ?? null);
        if ($last !== null && $expires !== null && $expires < $last) {
            $expires = null;
        }
        $service = $this->lists->of($factory, 'services', all: true)->firstWhere('code', $code);
        $audit = $service === null ? null : Audit::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $factory->getKey())->where('service_id', $service->getKey())->first();
        // Only a request this very connection carried for this factory.
        $request = RenewalRequest::query()->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $factory->getKey())
            ->where('connection_id', $delivery->connectionId)->whereKey((string) ($payload['request_id'] ?? ''))->first();
        $text = fn (string $key, int $max) => ($value = mb_substr(trim((string) ($payload[$key] ?? '')), 0, $max)) === '' ? null : $value;

        $result = ProposedResult::query()->withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $factory->getKey(),
            'audit_id' => $audit?->getKey(),
            'request_id' => $request?->getKey(),
            'connection_id' => $delivery->connectionId,
            'message_id' => $delivery->messageId,
            'from_name' => mb_substr($delivery->fromName, 0, 190),
            'service_code' => $code,
            'service_name' => mb_substr(trim((string) ($payload['service']['name'] ?? '')), 0, 190) ?: null,
            'last_audit_on' => $last,
            'expires_on' => $expires,
            'result' => $text('result', 60),
            'certificate_no' => $text('certificate_no', 100),
            'audit_type_code' => $text('audit_type', 30),
            'note' => $text('note', 2000),
            'files' => $delivery->files,
            'status' => ProposedResult::PENDING,
            'version' => 1,
        ]);
        $this->audit->record('compliance.result_received', $result, new: ['service' => $code, 'from' => $delivery->fromName, 'expires_on' => $expires],
            organizationId: $factory->getKey());

        if ($this->rules->get('compliance.auto_apply_results', $this->contexts->forOrganization($factory)) === true) {
            try {
                $this->apply($factory, $result, $result->version, null);

                return $result->getKey();
            } catch (ComplianceException) {
                // Not applicable as it is (e.g. the service is not in the lists): left for a person.
            }
        }
        $company = $this->place->companyOf($factory);
        $this->notifier->notify('compliance.result_received', $this->recipients->holding('compliance.confirm', $factory), fn (string $locale) => [
            'organization' => $factory->displayName($locale),
            'other' => $delivery->fromName,
            'service' => $result->service_name ?? $code,
        ], $company->partner, $company);

        return $result->getKey();
    }

    /** Into the register: the line's result and certificate, then its official dates with the reason. */
    public function apply(Organization $unit, ProposedResult $result, int $baseVersion, ?User $actor): ProposedResult
    {
        $factory = Organization::query()->findOrFail($result->organization_id);

        return $this->place->transaction($unit, function () use ($factory, $result, $baseVersion, $actor) {
            $result = $this->locked($result, $baseVersion);
            $reason = __('compliance::compliance.renewal.applied_reason', ['provider' => $result->from_name]);
            $type = $result->audit_type_code === null ? null : $this->lists->of($factory, 'types', all: true)->firstWhere('code', $result->audit_type_code);
            $details = array_filter([
                'result' => $result->result,
                'certificate_no' => $result->certificate_no,
                'audit_type_id' => $type?->getKey(),
            ], fn ($value) => $value !== null);
            $dates = ['last_audit_on' => $result->last_audit_on?->toDateString(), 'expires_on' => $result->expires_on?->toDateString()];

            $audit = $result->audit_id === null ? null : Audit::inTenantOf($factory)->withoutGlobalScope(OrganizationScope::class)->find($result->audit_id);
            if ($audit === null) {
                $service = $this->lists->of($factory, 'services', all: true)->firstWhere('code', $result->service_code)
                    ?? throw ComplianceException::resultServiceUnknown($result->service_code);
                $audit = $this->register->add($factory, [...$details, ...array_filter($dates), 'service_id' => $service->getKey(), 'reason' => $reason], true, $actor, 'from_provider');
            } else {
                if ($details !== []) {
                    $audit = $this->register->update($factory, $audit, $audit->version, [...$details, 'reason' => $reason], $actor, 'from_provider');
                }
                if (array_filter($dates) !== []) {
                    $audit = $this->register->setDates($factory, $audit, $audit->version,
                        $dates['last_audit_on'] ?? $audit->last_audit_on?->toDateString(), $dates['expires_on'] ?? $audit->expires_on?->toDateString(), $reason, $actor, 'from_provider');
                }
            }

            $result->forceFill([
                'audit_id' => $audit->getKey(),
                'status' => ProposedResult::APPLIED,
                'decided_by' => $actor?->getKey(),
                'decided_at' => now(),
                'version' => $result->version + 1,
            ])->save();
            if ($result->request_id !== null) {
                RenewalRequest::inTenantOf($factory)->withoutGlobalScope(OrganizationScope::class)->whereKey($result->request_id)
                    ->whereIn('status', RenewalRequest::OPEN)->get()
                    ->each(fn (RenewalRequest $request) => $request->forceFill(['status' => RenewalRequest::DONE, 'version' => $request->version + 1])->save());
            }
            $this->audit->record('compliance.result_applied', $result, new: ['audit_id' => $audit->getKey(), ...$dates], actor: $actor, organizationId: $factory->getKey());

            return $result;
        });
    }

    public function reject(Organization $unit, ProposedResult $result, int $baseVersion, string $reason, User $actor): ProposedResult
    {
        return $this->place->transaction($unit, function () use ($result, $baseVersion, $reason, $actor) {
            $result = $this->locked($result, $baseVersion);
            $result->forceFill([
                'status' => ProposedResult::REJECTED,
                'reject_reason' => $reason,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'version' => $result->version + 1,
            ])->save();
            $this->audit->record('compliance.result_rejected', $result, reason: $reason, actor: $actor, organizationId: $result->organization_id);

            return $result;
        });
    }

    /** Short-lived link to one file of a result (routes/web.php of the module). */
    public function link(ProposedResult $result, int $index): string
    {
        return URL::temporarySignedRoute('compliance.results.file', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $result->organization_id,
            'result' => $result->getKey(),
            'index' => $index,
        ], absolute: false);
    }

    private function locked(ProposedResult $result, int $baseVersion): ProposedResult
    {
        /** @var ProposedResult $fresh */
        $fresh = ProposedResult::inTenantOf($result->organization_id)->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($result->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->status !== ProposedResult::PENDING) {
            throw ComplianceException::resultDecided();
        }
        if ($fresh->version !== $baseVersion) {
            throw ComplianceException::versionConflict();
        }

        return $fresh;
    }
}
