<?php

namespace Modules\Outpatient\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\SmsSender;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Outpatient\Events\SerialBooked;
use Modules\Outpatient\Models\Chamber;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\Schedule;
use Modules\Outpatient\Models\Serial;
use Modules\Outpatient\Services\OutpatientStore;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * When the rule outpatient.serial_sms is on at the chamber's branch (and SMS
 * is on for the partner), the patient gets a text with the doctor, the date,
 * the time and the serial number. Queued: booking never waits for the SMS
 * provider. Nothing else about the patient goes in the text.
 */
class SmsWhenSerialBooked implements ShouldQueue
{
    public function __construct(
        private OutpatientStore $store,
        private PatientDirectory $patients,
        private Notifier $notifier,
        private SmsSender $sms,
        private ModuleResolver $modules,
    ) {}

    public function handle(SerialBooked $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company === null || ! $this->modules->isEnabled('outpatient', $company) || ! $this->sms->enabled($company->partner)) {
            return;
        }
        $serial = $this->store->query(Serial::class, $company)->find($event->serialId);
        $unit = $serial === null ? null : Organization::query()->find($serial->unit_id);
        if ($unit === null || ! (bool) $this->store->rule('serial_sms', $unit)) {
            return;
        }
        $patient = $this->patients->find($company, $serial->patient_id);
        if ($patient === null || ($patient->mobile ?? '') === '') {
            return;
        }
        $doctor = $this->store->query(Doctor::class, $company)->find($serial->doctor_id);
        $schedule = $this->store->query(Schedule::class, $company)->find($serial->schedule_id);
        $chamber = $this->store->query(Chamber::class, $company)->find($serial->chamber_id);

        $this->notifier->notifyContacts('outpatient.serial_booked', [['name' => $patient->name, 'phone' => $patient->mobile]], fn (string $locale) => [
            'organization' => $company->displayName($locale),
            'doctor' => $doctor?->textIn('name', $locale) ?? '',
            'date' => $serial->date->toDateString(),
            'time' => (string) ($schedule?->starts_at ?? ''),
            'number' => (string) $serial->serial_no,
            'chamber' => trim(($chamber?->name ?? '').' '.($chamber?->room ?? '')),
        ], $company->partner, $company, ['sms']);
    }
}
