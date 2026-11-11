<?php

namespace Modules\Inpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Inpatient\Exceptions\InpatientException;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\Bed;
use Modules\Inpatient\Models\Ward;
use Modules\PatientBilling\Contracts\PatientCharges;

/**
 * Beds of a ward: code (unique in the ward), the billing service of the
 * daily charge (checked against Patient billing), in use or not. A bed that
 * ever had a patient stays (switched off instead). The desk marks a bed
 * clean (free) or blocked; occupied comes only from admissions. Audited.
 */
class Beds
{
    /** Where a bed may be put by hand. */
    private const BY_HAND = [
        Bed::CLEANING => [Bed::FREE, Bed::BLOCKED],
        Bed::FREE => [Bed::BLOCKED],
        Bed::BLOCKED => [Bed::FREE],
    ];

    public function __construct(private InpatientStore $store, private PatientCharges $charges, private AuditLogger $audit) {}

    /** @param  array{ward_id?: string, code?: string, service_code?: string, is_active?: bool}  $data */
    public function save(Organization $company, Organization $unit, ?Bed $bed, array $data, ?int $baseVersion, User $actor): Bed
    {
        if (isset($data['service_code']) && ! array_key_exists($data['service_code'], $this->charges->services($company))) {
            throw ValidationException::withMessages(['service_code' => __('inpatient::messages.validation.service_code')]);
        }
        $ward = $this->store->query(Ward::class, $company)->whereKey($data['ward_id'] ?? $bed?->ward_id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first();
        if ($ward === null) {
            throw ValidationException::withMessages(['ward_id' => __('inpatient::messages.errors.bad_link')]);
        }
        $code = strtoupper(trim((string) ($data['code'] ?? $bed?->code)));
        if ($this->store->query(Bed::class, $company)->where('ward_id', $ward->getKey())->where('code', $code)->when($bed !== null, fn ($q) => $q->whereKeyNot($bed->getKey()))->exists()) {
            throw ValidationException::withMessages(['code' => __('inpatient::messages.validation.taken')]);
        }

        return $this->store->transaction($company, function () use ($company, $bed, $data, $baseVersion, $actor, $ward, $code) {
            if ($bed !== null) {
                $bed = $this->store->query(Bed::class, $company)->whereKey($bed->getKey())->lockForUpdate()->firstOrFail();
                if ($bed->version !== $baseVersion) {
                    throw InpatientException::versionConflict(['version' => $bed->version]);
                }
                if (($data['is_active'] ?? true) === false && $bed->status === Bed::OCCUPIED) {
                    throw InpatientException::because('bed_occupied', 409);
                }
                $old = $bed->only(['ward_id', 'code', 'service_code', 'is_active']);
                $bed->fill(['ward_id' => $ward->getKey(), 'unit_id' => $ward->unit_id, 'code' => $code, ...array_intersect_key($data, array_flip(['service_code', 'is_active']))]);
                $bed->version++;
            } else {
                $old = [];
                $bed = new Bed(['organization_id' => $company->getKey(), 'unit_id' => $ward->unit_id, 'ward_id' => $ward->getKey(), 'code' => $code,
                    'service_code' => $data['service_code'], 'is_active' => $data['is_active'] ?? true]);
            }
            $bed->save();
            $this->audit->record($old === [] ? 'inpatient.bed_created' : 'inpatient.bed_updated', $bed, old: $old, new: $bed->only(['ward_id', 'code', 'service_code', 'is_active']), actor: $actor, organizationId: $company->getKey());

            return $bed;
        });
    }

    public function delete(Organization $company, Bed $bed, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $bed, $baseVersion, $actor) {
            $bed = $this->store->query(Bed::class, $company)->whereKey($bed->getKey())->lockForUpdate()->firstOrFail();
            if ($bed->version !== $baseVersion) {
                throw InpatientException::versionConflict(['version' => $bed->version]);
            }
            if ($this->store->query(Admission::class, $company)->where('bed_id', $bed->getKey())->exists() || $bed->status === Bed::OCCUPIED) {
                throw InpatientException::inUse();
            }
            $this->audit->record('inpatient.bed_deleted', $bed, old: $bed->only(['ward_id', 'code']), actor: $actor, organizationId: $company->getKey());
            $bed->delete();
        });
    }

    /** Cleaned (free) or blocked, by hand. */
    public function setStatus(Organization $company, Bed $bed, string $status, int $baseVersion, User $actor): Bed
    {
        return $this->store->transaction($company, function () use ($company, $bed, $status, $baseVersion, $actor) {
            $bed = $this->store->query(Bed::class, $company)->whereKey($bed->getKey())->lockForUpdate()->firstOrFail();
            if ($bed->version !== $baseVersion) {
                throw InpatientException::versionConflict(['version' => $bed->version, 'status' => $bed->status]);
            }
            if (! in_array($status, self::BY_HAND[$bed->status] ?? [], true)) {
                throw InpatientException::because('cannot_set_bed', 409, ['from' => $bed->status, 'to' => $status]);
            }
            $old = $bed->status;
            $bed->forceFill(['status' => $status, 'version' => $bed->version + 1])->save();
            $this->audit->record('inpatient.bed_status', $bed, old: ['status' => $old], new: ['status' => $status, 'code' => $bed->code], actor: $actor, organizationId: $company->getKey());

            return $bed;
        });
    }
}
