<?php

namespace Modules\Outpatient\Services;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Outpatient\Models\Chamber;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\Schedule;
use Modules\Outpatient\Models\Serial;
use Modules\PatientBilling\Contracts\PatientCharges;
use Modules\Patients\Contracts\PatientDirectory;

/** Serials as the screens read them: the desk's list, the slip, the doctor's own list. */
class SerialView
{
    public function __construct(private OutpatientStore $store, private PatientDirectory $patients, private PatientCharges $charges) {}

    /**
     * @param  Collection<int, Serial>  $serials
     * @return list<array<string, mixed>>
     */
    public function rows(Organization $company, Collection $serials): array
    {
        $doctors = $this->store->query(Doctor::class, $company)->whereKey($serials->pluck('doctor_id')->unique()->all())->get()->keyBy(fn ($d) => (string) $d->getKey());
        $chambers = $this->store->query(Chamber::class, $company)->whereKey($serials->pluck('chamber_id')->unique()->all())->get()->keyBy(fn ($c) => (string) $c->getKey());
        $schedules = $this->store->query(Schedule::class, $company)->whereKey($serials->pluck('schedule_id')->unique()->all())->get()->keyBy(fn ($s) => (string) $s->getKey());

        return $serials->map(fn (Serial $serial) => $this->row($company, $serial, $doctors, $chambers, $schedules))->values()->all();
    }

    /** @return array<string, mixed> One serial with its fee (for the slip). */
    public function full(Organization $company, Serial $serial): array
    {
        $row = $this->rows($company, collect([$serial]))[0];
        $row['due_minor'] = $serial->bill_id === null ? null : $this->charges->dueOn($company, $serial->bill_id);
        $row['fee_minor'] = $serial->bill_id === null ? 0 : ($this->charges->chargesOn($company, $serial->bill_id, 'outpatient')['lines'][(string) $serial->getKey()] ?? 0);
        $row['currency'] = rescue(fn () => $this->store->currency($company), null, false);

        return $row;
    }

    /** @return array<string, mixed> */
    private function row(Organization $company, Serial $serial, Collection $doctors, Collection $chambers, Collection $schedules): array
    {
        $patient = $this->patients->find($company, $serial->patient_id);
        $doctor = $doctors->get((string) $serial->doctor_id);
        $chamber = $chambers->get((string) $serial->chamber_id);
        $schedule = $schedules->get((string) $serial->schedule_id);

        return [
            'id' => $serial->getKey(),
            'unit_id' => $serial->unit_id,
            'date' => $serial->date->toDateString(),
            'serial_no' => $serial->serial_no,
            'status' => $serial->status,
            'kind' => $serial->kind,
            'is_free' => $serial->is_free,
            'bill_id' => $serial->bill_id,
            'patient' => $patient === null ? null : ['id' => $patient->id, 'mrn' => $patient->mrn, 'name' => $patient->name, 'sex' => $patient->sex, 'age' => $patient->age],
            'doctor' => $doctor === null ? null : ['id' => $doctor->getKey(), 'name' => $doctor->textIn('name'), 'specialty' => $doctor->specialty],
            'chamber' => $chamber === null ? null : ['id' => $chamber->getKey(), 'name' => $chamber->name, 'room' => $chamber->room],
            'session' => $schedule === null ? null : ['id' => $schedule->getKey(), 'starts_at' => $schedule->starts_at, 'ends_at' => $schedule->ends_at],
            'arrived_at' => $serial->arrived_at?->toIso8601String(),
            'called_at' => $serial->called_at?->toIso8601String(),
            'finished_at' => $serial->finished_at?->toIso8601String(),
            'cancel_reason' => $serial->cancel_reason,
            'next' => array_values(Serial::NEXT[$serial->status] ?? []),
            'version' => $serial->version,
        ];
    }
}
