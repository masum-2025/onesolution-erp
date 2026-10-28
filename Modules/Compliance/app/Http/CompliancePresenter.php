<?php

namespace Modules\Compliance\Http;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Models\AuditChange;
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
            'provider' => $audit->provider === null ? null : ['id' => $audit->provider_id, 'name' => $audit->provider->name, 'on_platform' => $audit->provider->connection_id !== null],
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
            'can' => $factory === null || ! app(CurrentContext::class)->canWriteTo($factory->getKey()) ? ['edit' => false, 'dates' => false] : [
                'edit' => Gate::allows('compliance.manage', $factory),
                'dates' => Gate::allows('compliance.set_expiry', $factory),
            ],
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
            ? [...$base, 'name' => $item->name, 'email' => $item->email, 'phone' => $item->phone, 'on_platform' => $item->connection_id !== null]
            : [...$base, 'code' => $item->code, 'name' => $item->textIn('name'), 'names' => $item->texts('name'), 'sort_order' => $item->sort_order,
                ...($kind === 'services' ? ['validity_months' => $item->validity_months] : [])];
    }

    private function unit(string $id): ?Organization
    {
        if (! array_key_exists($id, $this->units)) {
            $this->units[$id] = Organization::query()->find($id);
        }

        return $this->units[$id];
    }
}
