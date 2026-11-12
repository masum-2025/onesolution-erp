<?php

namespace Modules\Outpatient\Directory;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Outpatient\Contracts\Prescriptions as PrescriptionsContract;
use Modules\Outpatient\Models\Consultation;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\PrescriptionItem;
use Modules\Outpatient\Services\OutpatientStore;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Finished prescriptions for the pharmacy counter (bound to the Prescriptions
 * contract): medicines and who wrote them, never what the doctor found.
 */
class Prescriptions implements PrescriptionsContract
{
    public function __construct(private OutpatientStore $store, private PatientDirectory $patients) {}

    public function search(Organization $company, Organization $unit, string $term, int $days, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        if (Str::isUlid($term)) {
            $found = $this->resolve($company, $unit, strtolower($term), $days);

            return $found === null ? [] : $this->rows($company, [$found]);
        }
        $patientIds = array_map(fn ($record) => $record->id, $this->patients->search($company, $term, 20));
        if ($patientIds === []) {
            return [];
        }

        return $this->rows($company, $this->inForce($company, $unit, $days)->whereIn('patient_id', $patientIds)->orderByDesc('finished_at')->limit(max(1, min(50, $limit)))->get()->all());
    }

    public function find(Organization $company, Organization $unit, string $id, int $days, ?User $viewer = null): ?array
    {
        $consultation = Str::isUlid($id) ? $this->resolve($company, $unit, strtolower($id), $days) : null;
        if ($consultation === null) {
            return null;
        }
        $patient = $this->patients->find($company, $consultation->patient_id);
        $doctor = $this->store->query(Doctor::class, $company)->find($consultation->doctor_id);
        if ($viewer !== null) {
            $this->patients->recordLook($company, $consultation->patient_id, $viewer, 'prescription');
        }
        $items = $this->store->query(PrescriptionItem::class, $company)->where('consultation_id', $consultation->getKey())->orderBy('position')->get();

        return [
            'id' => (string) $consultation->getKey(),
            'date' => $this->date($company, $consultation),
            'amended' => strtolower($id) !== (string) $consultation->getKey(),
            'patient_id' => (string) $consultation->patient_id,
            'patient_name' => $patient?->name ?? '',
            'patient_mrn' => $patient?->mrn ?? '',
            'doctor_name' => (string) ($doctor?->textIn('name') ?? ''),
            'doctor_registration_no' => $doctor?->registration_no,
            'medicines' => $items->map(fn (PrescriptionItem $item) => [
                'medicine_id' => $item->medicine_id, 'medicine_text' => (string) $item->medicine_text,
                'dose' => $item->dose, 'duration' => $item->duration, 'timing' => $item->timing,
            ])->values()->all(),
        ];
    }

    /** The prescription in force for this id (a replaced revision answers as its newest finished one). */
    private function resolve(Organization $company, Organization $unit, string $id, int $days): ?Consultation
    {
        $asked = $this->store->query(Consultation::class, $company)->whereKey($id)->first(['id', 'serial_id']);
        if ($asked === null) {
            return null;
        }

        return $this->inForce($company, $unit, $days)->where('serial_id', $asked->serial_id)->orderByDesc('revision')->first();
    }

    /** @return Builder<Consultation> Finished, not replaced, recent and within reach of the unit. */
    private function inForce(Organization $company, Organization $unit, int $days): Builder
    {
        $query = $this->store->query(Consultation::class, $company)->where('status', Consultation::FINISHED)->whereNull('superseded_at')
            ->where('finished_at', '>=', CarbonImmutable::now()->subDays(max(0, $days)));
        if (! $this->store->ruleOf('patients.share_between_branches', $unit)) {
            $query->whereIn('unit_id', $this->store->subtreeIds($unit));
        }

        return $query;
    }

    /**
     * @param  list<Consultation>  $consultations
     * @return list<array{id: string, date: string, patient_name: string, patient_mrn: string, doctor_name: string}>
     */
    private function rows(Organization $company, array $consultations): array
    {
        $doctors = $this->store->query(Doctor::class, $company)->whereKey(array_unique(array_map(fn ($c) => $c->doctor_id, $consultations)))->get()
            ->keyBy(fn ($doctor) => (string) $doctor->getKey());

        return array_map(function (Consultation $consultation) use ($company, $doctors) {
            $patient = $this->patients->find($company, $consultation->patient_id);

            return [
                'id' => (string) $consultation->getKey(),
                'date' => $this->date($company, $consultation),
                'patient_name' => $patient?->name ?? '',
                'patient_mrn' => $patient?->mrn ?? '',
                'doctor_name' => (string) ($doctors->get((string) $consultation->doctor_id)?->textIn('name') ?? ''),
            ];
        }, $consultations);
    }

    private function date(Organization $company, Consultation $consultation): string
    {
        return CarbonImmutable::parse($consultation->finished_at)->setTimezone($this->store->timezone($company))->toDateString();
    }
}
