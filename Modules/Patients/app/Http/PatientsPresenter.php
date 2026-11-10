<?php

namespace Modules\Patients\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Patients\Models\MergeRequest;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientView;
use Modules\Patients\Models\TimelineEntry;

/**
 * Patients as the screens read them. A list row carries no ID number,
 * allergies or address; the full record shows the ID number only to people
 * who may change patients (others see its last four characters).
 */
class PatientsPresenter
{
    /** @return array<string, mixed> */
    public function summary(Patient $patient, CarbonImmutable $today): array
    {
        return [
            'id' => $patient->getKey(),
            'mrn' => $patient->mrn,
            'name' => $patient->name,
            'name_local' => $patient->name_local,
            'sex' => $patient->sex,
            'age' => $patient->ageOn($today),
            'mobile' => $patient->mobile,
            'unit_id' => $patient->unit_id,
            'merged_into_id' => $patient->merged_into_id,
        ];
    }

    /** @return array<string, mixed> */
    public function patient(Patient $patient, CarbonImmutable $today, bool $fullIdNumber): array
    {
        return [
            ...$this->summary($patient, $today),
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'dob_estimated' => $patient->dob_estimated,
            'id_type' => $patient->id_type,
            'id_number' => $fullIdNumber ? $patient->id_number : $this->masked($patient->id_number),
            'guardian_name' => $patient->guardian_name,
            'guardian_relation' => $patient->guardian_relation,
            'blood_group' => $patient->blood_group,
            'allergies' => $patient->allergies,
            'address' => $patient->address,
            'version' => $patient->version,
            'created_at' => $patient->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function timeline(TimelineEntry $entry, ?string $locale): array
    {
        return [
            'id' => $entry->getKey(),
            'source_module' => $entry->source_module,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'title' => $entry->titleIn($locale),
            'happened_at' => $entry->happened_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $names  User id => name.
     * @return array<string, mixed>
     */
    public function view(PatientView $view, array $names): array
    {
        return [
            'id' => $view->getKey(),
            'user_id' => $view->user_id,
            'user_name' => $names[$view->user_id] ?? null,
            'purpose' => $view->purpose,
            'viewed_at' => $view->viewed_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, Patient>  $patients
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public function merge(MergeRequest $merge, array $patients, array $names, CarbonImmutable $today): array
    {
        $side = fn (string $id) => isset($patients[$id]) ? $this->summary($patients[$id], $today) : ['id' => $id];

        return [
            'id' => $merge->getKey(),
            'status' => $merge->status,
            'source' => $side($merge->source_id),
            'target' => $side($merge->target_id),
            'reason' => $merge->reason,
            'mine' => $merge->requested_by === auth()->id(),
            'requested_by_name' => $names[$merge->requested_by] ?? null,
            'decided_by_name' => $merge->decided_by === null ? null : ($names[$merge->decided_by] ?? null),
            'decided_at' => $merge->decided_at?->toIso8601String(),
            'decision_note' => $merge->decision_note,
            'created_at' => $merge->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function userNames(array $ids): array
    {
        return $ids === [] ? [] : User::query()->whereKey(array_values(array_unique($ids)))->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }

    private function masked(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) <= 4 ? '••••' : '•••• '.mb_substr($value, -4);
    }
}
