<?php

namespace Modules\PatientBilling\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Models\Bill;
use Modules\PatientBilling\Models\Panel;

/**
 * Corporate and panel clients: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class Panels
{
    public function __construct(private PatientBillingStore $store, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by PanelRequest.
     */
    public function save(Organization $company, Organization $unit, ?Panel $record, array $data, User $actor): Panel
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Panel::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor) {
            if ($record !== null) {
                $record = $this->store->query(Panel::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['base_version']) {
                    throw PatientBillingException::versionConflict(['version' => $record->version]);
                }
                $old = $record->attributesToArray();
                $record->fill(array_intersect_key($data, array_flip(['name', 'kind', 'credit_limit_minor', 'discount_percent', 'is_active'])));
                $record->version++;
            } else {
                $old = null;
                $record = new Panel([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(['name', 'kind', 'credit_limit_minor', 'discount_percent', 'is_active'])),
                ]);
                $record->currency = $this->store->currency($company);
            }
            $record->save();
            $this->audit->record($old === null ? 'patient_billing.panel_created' : 'patient_billing.panel_updated', $record,
                old: $old ?? [], new: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization $company, Panel $record, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $record, $baseVersion, $actor) {
            $record = $this->store->query(Panel::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== $baseVersion) {
                throw PatientBillingException::versionConflict(['version' => $record->version]);
            }
            if ($this->store->query(Bill::class, $company)->where('panel_id', $record->getKey())->exists()) {
                throw PatientBillingException::inUse();
            }
            $this->audit->record('patient_billing.panel_deleted', $record, old: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /** The title a list or a link shows for a Corporate or panel client. */
    public static function title(Panel $record): string
    {
        return (string) $record->name;
    }
}
