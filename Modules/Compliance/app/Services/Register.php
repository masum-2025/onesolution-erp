<?php

namespace Modules\Compliance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Models\AuditChange;
use Modules\Compliance\Support\Validity;

/**
 * The audit register: one line per factory and audit service. Adding a line
 * and changing its provider, kind, result or certificate needs
 * compliance.manage; its official dates (last audit, expiry) only
 * compliance.set_expiry, always with a reason. Every change is kept
 * (cmp_audit_changes) and audited.
 */
class Register
{
    public function __construct(
        private Place $place,
        private Lists $lists,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * Register lines at the unit and below, with the company of each (for its clock).
     *
     * @return Collection<int, Audit>
     */
    public function lines(Organization $unit): Collection
    {
        return $this->place->query(Audit::class, $unit, $this->place->subtreeIds($unit))->get();
    }

    /**
     * The service, provider and kind of each line. Lists may be kept by a unit
     * above the one signed in to, so the request's tenant scope is set aside:
     * the lines themselves were already found inside the unit.
     *
     * @template T of Audit|Collection<int, Audit>
     *
     * @param  T  $audits
     * @return T
     */
    public function withLists(Audit|Collection $audits): Audit|Collection
    {
        $free = fn ($query) => $query->withoutGlobalScope(OrganizationScope::class);

        return $audits->load(['service' => $free, 'provider' => $free, 'auditType' => $free]);
    }

    public function find(Organization $unit, string $id): Audit
    {
        return $this->place->query(Audit::class, $unit, $this->place->subtreeIds($unit))->whereKey($id)->first()
            ?? throw ComplianceException::auditNotFound();
    }

    public function expiringDays(Organization $unit): int
    {
        return (int) $this->rules->get('compliance.expiring_days', $this->contexts->forOrganization($unit));
    }

    /**
     * @return array{state: string, days_left: int|null}
     */
    public function standing(Audit $audit, CarbonImmutable $today, int $expiringDays): array
    {
        return ['state' => Validity::state($audit->expires_on, $today, $expiringDays), 'days_left' => Validity::daysLeft($audit->expires_on, $today)];
    }

    /**
     * @param  array{service_id: string, provider_id?: string|null, audit_type_id?: string|null, last_audit_on?: string|null, expires_on?: string|null, result?: string|null, certificate_no?: string|null, notes?: string|null, reason?: string|null}  $data
     */
    public function add(Organization $factory, array $data, bool $mayDate, ?User $actor, string $kind = 'added'): Audit
    {
        $this->place->assertFactory($factory);
        $company = $this->place->companyOf($factory);
        $this->checkLists($factory, $data);
        $dates = array_filter(['last_audit_on' => $data['last_audit_on'] ?? null, 'expires_on' => $data['expires_on'] ?? null]);
        if ($dates !== [] && ! $mayDate) {
            throw ComplianceException::datesNeedPermission();
        }
        $this->checkOrder($dates['last_audit_on'] ?? null, $dates['expires_on'] ?? null);

        return $this->place->transaction($factory, function () use ($factory, $company, $data, $dates, $actor, $kind) {
            $taken = $this->place->query(Audit::class, $factory, [$factory->getKey()])->where('service_id', $data['service_id'])->lockForUpdate()->exists();
            if ($taken) {
                throw ComplianceException::alreadyInRegister();
            }
            $audit = new Audit([
                ...array_intersect_key($data, array_flip(['service_id', 'provider_id', 'audit_type_id', 'result', 'certificate_no', 'notes'])),
                ...$dates,
                'organization_id' => $factory->getKey(),
                'company_id' => $company->getKey(),
                'version' => 1,
            ]);
            $audit->save();
            $this->change($audit, $kind, [], $this->values($audit), $data['reason'] ?? null, $actor);

            return $audit;
        });
    }

    /**
     * Provider, kind, result, certificate, notes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $unit, Audit $audit, int $baseVersion, array $data, ?User $actor, string $kind = 'details'): Audit
    {
        $factory = Organization::query()->findOrFail($audit->organization_id);
        $this->checkLists($factory, [...$data, 'service_id' => $audit->service_id]);

        return $this->place->transaction($unit, function () use ($audit, $baseVersion, $data, $actor, $kind) {
            $audit = $this->locked($audit, $baseVersion);
            $old = $this->values($audit);
            $audit->fill(array_intersect_key($data, array_flip(['provider_id', 'audit_type_id', 'result', 'certificate_no', 'notes'])));
            if (! $audit->isDirty()) {
                return $audit;
            }
            $audit->version++;
            $audit->save();
            $this->change($audit, $kind, $old, $this->values($audit), $data['reason'] ?? null, $actor);

            return $audit;
        });
    }

    /** The official last audit and expiry days, with a reason (compliance.set_expiry, checked by the caller). */
    public function setDates(Organization $unit, Audit $audit, int $baseVersion, ?string $lastAuditOn, ?string $expiresOn, string $reason, ?User $actor, string $kind = 'dates'): Audit
    {
        $this->checkOrder($lastAuditOn, $expiresOn);

        return $this->place->transaction($unit, function () use ($audit, $baseVersion, $lastAuditOn, $expiresOn, $reason, $actor, $kind) {
            $audit = $this->locked($audit, $baseVersion);
            $old = $this->values($audit);
            $audit->fill(['last_audit_on' => $lastAuditOn, 'expires_on' => $expiresOn]);
            if (! $audit->isDirty()) {
                return $audit;
            }
            $audit->version++;
            $audit->save();
            $this->change($audit, $kind, $old, $this->values($audit), $reason, $actor);

            return $audit;
        });
    }

    /**
     * @return Collection<int, AuditChange>
     */
    public function history(Organization $unit, Audit $audit): Collection
    {
        return $this->place->query(AuditChange::class, $unit, [$audit->organization_id])->where('audit_id', $audit->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /** Service, provider and kind must be in the lists usable at the factory (switched-off ones are kept as they are). */
    private function checkLists(Organization $factory, array $data): void
    {
        $this->lists->find($factory, 'services', (string) $data['service_id']);
        if (! empty($data['provider_id'])) {
            $this->lists->find($factory, 'providers', (string) $data['provider_id']);
        }
        if (! empty($data['audit_type_id'])) {
            $this->lists->find($factory, 'types', (string) $data['audit_type_id']);
        }
    }

    private function checkOrder(?string $lastAuditOn, ?string $expiresOn): void
    {
        if ($lastAuditOn !== null && $expiresOn !== null && $expiresOn < $lastAuditOn) {
            throw ComplianceException::datesOrder();
        }
    }

    private function locked(Audit $audit, int $baseVersion): Audit
    {
        /** @var Audit $fresh */
        $fresh = Audit::inTenantOf($audit->organization_id)->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($audit->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw ComplianceException::versionConflict();
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Audit $audit): array
    {
        return [
            ...$audit->only(['service_id', 'provider_id', 'audit_type_id', 'result', 'certificate_no']),
            'last_audit_on' => $audit->last_audit_on?->toDateString(),
            'expires_on' => $audit->expires_on?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function change(Audit $audit, string $kind, array $old, array $new, ?string $reason, ?User $actor): void
    {
        AuditChange::inTenantOf($audit->organization_id)->withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $audit->organization_id,
            'audit_id' => $audit->getKey(),
            'kind' => $kind,
            'old' => $old === [] ? null : (array_diff_assoc($old, $new) ?: null),
            'new' => $old === [] ? $new : array_intersect_key($new, array_diff_assoc($old, $new)),
            'reason' => $reason,
            'user_id' => $actor?->getKey(),
            'created_at' => now(),
        ]);
        $this->audit->record($kind === 'dates' ? 'compliance.dates_set' : "compliance.audit_{$kind}", $audit, old: $old, new: $new, reason: $reason,
            actor: $actor, organizationId: $audit->organization_id);
    }
}
