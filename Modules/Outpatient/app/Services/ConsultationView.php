<?php

namespace Modules\Outpatient\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\Outpatient\Models\AdvisedTest;
use Modules\Outpatient\Models\Consultation;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\PrescriptionItem;
use Modules\Outpatient\Models\Serial;

/** Consultations as the screens, the printed prescription and the portal read them. */
class ConsultationView
{
    public function __construct(private OutpatientStore $store) {}

    /** @return array<string, mixed> */
    public function full(Organization $company, Consultation $consultation): array
    {
        $items = $this->store->query(PrescriptionItem::class, $company)->where('consultation_id', $consultation->getKey())->orderBy('position')->get();
        $tests = $this->store->query(AdvisedTest::class, $company)->where('consultation_id', $consultation->getKey())->orderBy('position')->get();

        return [
            'id' => $consultation->getKey(),
            'serial_id' => $consultation->serial_id,
            'revision' => $consultation->revision,
            'status' => $consultation->status,
            'complaints' => $consultation->complaints,
            'findings' => (object) ($consultation->findings ?? []),
            'diagnosis' => $consultation->diagnosis,
            'advice' => $consultation->advice,
            'follow_up_on' => $consultation->follow_up_on?->toDateString(),
            'amend_reason' => $consultation->amend_reason,
            'finished_at' => $consultation->finished_at?->toIso8601String(),
            'superseded' => $consultation->superseded_at !== null,
            'lab_order_id' => $consultation->lab_order_id,
            'medicines' => $items->map(fn (PrescriptionItem $item) => [
                'medicine_id' => $item->medicine_id, 'medicine_text' => $item->medicine_text, 'dose' => $item->dose,
                'duration' => $item->duration, 'timing' => $item->timing, 'note' => $item->note,
            ])->values()->all(),
            'tests' => $tests->map(fn (AdvisedTest $test) => ['test_code' => $test->test_code, 'test_text' => $test->test_text])->values()->all(),
            'version' => $consultation->version,
        ];
    }

    /** @return array<string, mixed> The doctor as the prescription's head shows them. */
    public function doctor(Organization $company, string $doctorId): array
    {
        $doctor = $this->store->query(Doctor::class, $company)->find($doctorId);

        return $doctor === null ? [] : [
            'name' => (object) $doctor->texts('name'), 'degrees' => $doctor->degrees, 'specialty' => $doctor->specialty, 'registration_no' => $doctor->registration_no,
        ];
    }

    /**
     * A patient's earlier visits in force (newest first), for the doctor: date, doctor and diagnosis.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Organization $company, string $patientId, string $exceptSerialId, int $limit = 10): array
    {
        $visits = $this->store->query(Consultation::class, $company)->where('patient_id', $patientId)->where('serial_id', '!=', $exceptSerialId)
            ->where('status', Consultation::FINISHED)->whereNull('superseded_at')->orderByDesc('finished_at')->limit($limit)->get();
        $doctors = $this->store->query(Doctor::class, $company)->whereKey($visits->pluck('doctor_id')->unique()->all())->get()->keyBy(fn ($d) => (string) $d->getKey());
        $dates = $this->store->query(Serial::class, $company)->whereKey($visits->pluck('serial_id')->all())->get(['id', 'date'])->keyBy(fn ($s) => (string) $s->getKey());

        return $visits->map(fn (Consultation $visit) => [
            'serial_id' => $visit->serial_id,
            'date' => $dates->get((string) $visit->serial_id)?->date?->toDateString(),
            'doctor' => $doctors->get((string) $visit->doctor_id)?->textIn('name'),
            'diagnosis' => $visit->diagnosis,
        ])->values()->all();
    }
}
