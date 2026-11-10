<?php

namespace Modules\Patients\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Exceptions\PatientsException;
use Modules\Patients\Models\CareLink;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientView;

/**
 * Which patients a person may see at a unit, and the record of each look.
 *
 * - Patients of the whole company when patients.share_between_branches is on
 *   (a patient is found at any branch), else of the unit and below.
 * - With patients.doctor_sees = own, a clinician (patients.treat without
 *   patients.register or patients.manage) sees only patients linked to them
 *   by a care link (a visit, a ward).
 * Anything else is the same 404 as a record that does not exist.
 */
class PatientAccess
{
    public function __construct(private PatientsStore $store) {}

    /** @return Builder<Patient> */
    public function visible(Organization $company, Organization $unit, User $user): Builder
    {
        $query = $this->store->query(Patient::class, $company);
        if (! $this->store->rule('share_between_branches', $unit)) {
            $query->whereIn('unit_id', $this->store->subtreeIds($unit));
        }
        if ($this->onlyOwn($unit, $user)) {
            $query->whereIn('id', $this->store->query(CareLink::class, $company)->where('user_id', $user->getKey())->select('patient_id'));
        }

        return $query;
    }

    public function find(Organization $company, Organization $unit, User $user, string $id): Patient
    {
        return $this->visible($company, $unit, $user)->whereKey($id)->first() ?? throw PatientsException::notFound('patient');
    }

    /** Whether the person is a clinician limited to their own patients here. */
    public function onlyOwn(Organization $unit, User $user): bool
    {
        return $this->store->rule('doctor_sees', $unit) === 'own'
            && Gate::forUser($user)->allows('patients.treat', $unit)
            && ! Gate::forUser($user)->any(['patients.register', 'patients.manage'], $unit);
    }

    /** Writes that the person opened the record (append-only). */
    public function logView(Organization $company, Patient $patient, User $user, string $purpose): void
    {
        (new PatientView([
            'organization_id' => $company->getKey(), 'patient_id' => $patient->getKey(), 'user_id' => $user->getKey(),
            'purpose' => $purpose, 'viewed_at' => CarbonImmutable::now(),
        ]))->save();
    }

    /** Links a member of staff to a patient they care for (once). */
    public function linkCarer(Organization $company, string $patientId, string $userId, string $sourceModule): void
    {
        $this->store->query(CareLink::class, $company)->firstOrCreate(
            ['patient_id' => $patientId, 'user_id' => $userId],
            ['organization_id' => $company->getKey(), 'source_module' => $sourceModule],
        );
    }
}
