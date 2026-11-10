<?php

namespace Modules\PatientBilling\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Models\BillLine;
use Modules\PatientBilling\Models\Service;

/**
 * Services and prices: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class Services
{
    public function __construct(private PatientBillingStore $store, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by ServiceRequest.
     */
    public function save(Organization $company, Organization $unit, ?Service $record, array $data, User $actor): Service
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Service::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor) {
            if ($record !== null) {
                $record = $this->store->query(Service::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['base_version']) {
                    throw PatientBillingException::versionConflict(['version' => $record->version]);
                }
                $old = $record->attributesToArray();
                $record->fill(array_intersect_key($data, array_flip(['code', 'category', 'price_minor', 'tax_code_id', 'is_active'])));
                $record->version++;
            } else {
                $old = null;
                $record = new Service([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(['code', 'category', 'price_minor', 'tax_code_id', 'is_active'])),
                ]);
                $record->currency = $this->store->currency($company);
            }
            if (array_key_exists('name', $data)) {
                $record->putTexts('name', $data['name']);
            }
            if ($record->code !== null && $this->store->query(Service::class, $company)->where('code', $record->code)
                ->when($record->exists, fn ($query) => $query->whereKeyNot($record->getKey()))->exists()) {
                throw ValidationException::withMessages(['code' => __('patient_billing::messages.validation.taken')]);
            }
            $record->save();
            $this->audit->record($old === null ? 'patient_billing.service_created' : 'patient_billing.service_updated', $record,
                old: $old ?? [], new: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization $company, Service $record, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $record, $baseVersion, $actor) {
            $record = $this->store->query(Service::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== $baseVersion) {
                throw PatientBillingException::versionConflict(['version' => $record->version]);
            }
            if ($this->store->query(BillLine::class, $company)->where('service_id', $record->getKey())->exists()) {
                throw PatientBillingException::inUse();
            }
            $this->audit->record('patient_billing.service_deleted', $record, old: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /** The title a list or a link shows for a Service. */
    public static function title(Service $record): string
    {
        return $record->textIn('name');
    }
}
