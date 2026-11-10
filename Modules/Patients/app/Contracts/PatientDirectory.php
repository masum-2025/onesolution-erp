<?php

namespace Modules\Patients\Contracts;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Patients\Directory\PatientRecord;

/**
 * How other modules (billing, diagnostics, outpatient, inpatient, pharmacy)
 * reach patients: never through the patients tables. Lookups are limited to
 * the company; a merged patient answers as the record it was merged into.
 * The person's own visibility (patients.doctor_sees) is the calling module's
 * screen's concern only where it shows patient lists.
 */
interface PatientDirectory
{
    public function find(Organization $company, string $id): ?PatientRecord;

    public function exists(Organization $company, string $id): bool;

    public function label(Organization $company, string $id): ?string;

    /**
     * By patient number, mobile or name; merged patients left out.
     *
     * @return list<PatientRecord>
     */
    public function search(Organization $company, string $term, int $limit = 20): array;

    /**
     * A line in the patient's history, once per source record (writing it again updates it).
     *
     * @param  array<string, string>  $title  {"bn": …, "en": …}; no amounts or diagnoses.
     */
    public function addToTimeline(Organization $company, string $patientId, string $sourceModule, string $sourceType, string $sourceId, array $title, CarbonImmutable $happenedAt): void;

    /** A member of staff now cares for the patient (used by patients.doctor_sees = own). */
    public function linkCarer(Organization $company, string $patientId, string $userId, string $sourceModule): void;
}
