<?php

namespace Modules\Patients\Services;

use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Patients\Models\TimelineEntry;

/**
 * A patient's history: one line per source record (a visit, report,
 * prescription, bill), written once by the module that owns it. Writing the
 * same source again changes its title and time (a report finished later).
 */
class PatientTimeline
{
    public function __construct(private PatientsStore $store) {}

    /**
     * @param  array<string, string>  $title  {"bn": …, "en": …}; no amounts or diagnoses.
     */
    public function record(Organization $company, string $patientId, string $sourceModule, string $sourceType, string $sourceId, array $title, CarbonImmutable $happenedAt): TimelineEntry
    {
        $find = fn () => $this->store->query(TimelineEntry::class, $company)
            ->where('source_module', $sourceModule)->where('source_type', $sourceType)->where('source_id', $sourceId)->first();
        $entry = $find() ?? new TimelineEntry([
            'organization_id' => $company->getKey(), 'source_module' => $sourceModule, 'source_type' => $sourceType, 'source_id' => $sourceId,
        ]);
        $entry->fill(['patient_id' => $patientId, 'title' => array_map(fn ($text) => mb_substr((string) $text, 0, 200), $title), 'happened_at' => $happenedAt->utc()]);
        try {
            $entry->save();
        } catch (UniqueConstraintViolationException) {
            // Written at the same moment by another request: keep that one.
            return $find();
        }

        return $entry;
    }
}
