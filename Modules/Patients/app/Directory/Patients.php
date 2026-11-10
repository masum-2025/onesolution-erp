<?php

namespace Modules\Patients\Directory;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Patients\Contracts\PatientDirectory;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccess;
use Modules\Patients\Services\PatientSearch;
use Modules\Patients\Services\PatientsStore;
use Modules\Patients\Services\PatientTimeline;

/**
 * The patients directory for other modules (bound to PatientDirectory).
 */
class Patients implements PatientDirectory
{
    /** How many merges are followed before giving up (a loop is never made, this only guards). */
    private const MAX_HOPS = 5;

    public function __construct(
        private PatientsStore $store,
        private PatientSearch $search,
        private PatientTimeline $timeline,
        private PatientAccess $access,
    ) {}

    public function find(Organization $company, string $id): ?PatientRecord
    {
        $patient = $this->resolve($company, $id);

        return $patient === null ? null : $this->record($company, $patient);
    }

    public function exists(Organization $company, string $id): bool
    {
        return $this->resolve($company, $id) !== null;
    }

    public function label(Organization $company, string $id): ?string
    {
        return $this->find($company, $id)?->label();
    }

    public function search(Organization $company, string $term, int $limit = 20): array
    {
        return $this->search->apply($company, $this->store->query(Patient::class, $company), $term)
            ->limit(max(1, min(50, $limit)))->get()
            ->map(fn (Patient $patient) => $this->record($company, $patient))->all();
    }

    public function addToTimeline(Organization $company, string $patientId, string $sourceModule, string $sourceType, string $sourceId, array $title, CarbonImmutable $happenedAt): void
    {
        $patient = $this->resolve($company, $patientId);
        if ($patient !== null) {
            $this->timeline->record($company, $patient->getKey(), $sourceModule, $sourceType, $sourceId, $title, $happenedAt);
        }
    }

    public function linkCarer(Organization $company, string $patientId, string $userId, string $sourceModule): void
    {
        $patient = $this->resolve($company, $patientId);
        if ($patient !== null) {
            $this->access->linkCarer($company, $patient->getKey(), $userId, $sourceModule);
        }
    }

    private function resolve(Organization $company, string $id): ?Patient
    {
        $patient = $this->store->query(Patient::class, $company)->whereKey($id)->first();
        for ($hop = 0; $patient?->isMerged() && $hop < self::MAX_HOPS; $hop++) {
            $patient = $this->store->query(Patient::class, $company)->whereKey($patient->merged_into_id)->first();
        }

        return $patient?->isMerged() ? null : $patient;
    }

    private function record(Organization $company, Patient $patient): PatientRecord
    {
        return new PatientRecord($patient->getKey(), $patient->mrn, $patient->name, $patient->sex, $patient->ageOn($this->store->today($company)), $patient->mobile, $patient->unit_id);
    }
}
