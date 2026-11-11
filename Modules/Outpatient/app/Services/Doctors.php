<?php

namespace Modules\Outpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Modules\Outpatient\Exceptions\OutpatientException;
use Illuminate\Validation\ValidationException;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\Schedule;
use Modules\Outpatient\Models\Serial;
use Modules\PatientBilling\Contracts\PatientCharges;

/**
 * Doctors: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class Doctors
{
    public function __construct(private OutpatientStore $store, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by DoctorRequest.
     */
    public function save(Organization $company, Organization $unit, ?Doctor $record, array $data, User $actor): Doctor
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Doctor::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }

        $services = app(PatientCharges::class)->services($company);
        foreach (['fee_service_code', 'follow_up_service_code'] as $field) {
            if (($data[$field] ?? null) !== null && ! array_key_exists($data[$field], $services)) {
                throw ValidationException::withMessages([$field => __('outpatient::messages.validation.service_code')]);
            }
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor) {
            if ($record !== null) {
                $record = $this->store->query(Doctor::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['base_version']) {
                    throw OutpatientException::versionConflict(['version' => $record->version]);
                }
                $old = $record->attributesToArray();
                $record->fill(array_intersect_key($data, array_flip(['registration_no', 'degrees', 'specialty', 'mobile', 'fee_service_code', 'follow_up_service_code', 'is_active'])));
                $record->version++;
            } else {
                $old = null;
                $record = new Doctor([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(['registration_no', 'degrees', 'specialty', 'mobile', 'fee_service_code', 'follow_up_service_code', 'is_active'])),
                ]);
            }
            if (array_key_exists('name', $data)) {
                $record->putTexts('name', $data['name']);
            }
            $record->save();
            $this->audit->record($old === null ? 'outpatient.doctor_created' : 'outpatient.doctor_updated', $record,
                old: $old ?? [], new: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization $company, Doctor $record, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $record, $baseVersion, $actor) {
            $record = $this->store->query(Doctor::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== $baseVersion) {
                throw OutpatientException::versionConflict(['version' => $record->version]);
            }
            if ($this->store->query(Serial::class, $company)->where('doctor_id', $record->getKey())->exists()
                || $this->store->query(Schedule::class, $company)->where('doctor_id', $record->getKey())->exists()) {
                throw OutpatientException::inUse();
            }
            $this->audit->record('outpatient.doctor_deleted', $record, old: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /** The title a list or a link shows for a Doctor. */
    public static function title(Doctor $record): string
    {
        return $record->textIn('name');
    }
}
