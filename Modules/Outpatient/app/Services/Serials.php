<?php

namespace Modules\Outpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Outpatient\Events\SerialBooked;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Models\Chamber;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\Schedule;
use Modules\Outpatient\Models\Serial;
use Modules\PatientBilling\Contracts\PatientCharges;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Serials for a doctor's session on a date.
 *
 * - A session takes its own number of serials, else the rule
 *   outpatient.daily_serial_limit; numbers are given in order under a lock on
 *   the session, and a cancelled number is not given again.
 * - The fee goes on the patient's bill at the chamber's unit (Patient
 *   billing's PatientCharges): the doctor's fee, or for a follow-up the
 *   follow-up fee when set. Coming back within outpatient.follow_up_free_days
 *   of the last paid, finished visit to the same doctor costs nothing.
 * - Booked -> arrived -> in -> done; absent and cancelled on the way. A
 *   cancelled serial's fee is taken off the bill while the bill is open.
 * - With patients.doctor_sees = own a doctor who only consults sees and moves
 *   only their own serials.
 * Every step is audited (numbers and codes, never the patient's details).
 */
class Serials
{
    public function __construct(
        private OutpatientStore $store,
        private PatientDirectory $patients,
        private PatientCharges $charges,
        private AuditLogger $audit,
    ) {}

    /**
     * The sessions held on a date at the unit and below (one doctor, or all), with places taken.
     *
     * @return list<array<string, mixed>>
     */
    public function sessions(Organization $company, Organization $unit, CarbonImmutable $date, ?string $doctorId = null): array
    {
        $chambers = $this->store->query(Chamber::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))->where('is_active', true)->get()->keyBy(fn ($c) => (string) $c->getKey());
        $schedules = $this->store->query(Schedule::class, $company)->where('is_active', true)->where('weekday', $date->dayOfWeek)
            ->whereIn('chamber_id', $chambers->keys()->all())->when($doctorId !== null, fn ($q) => $q->where('doctor_id', $doctorId))
            ->orderBy('starts_at')->get();
        $doctors = $this->store->query(Doctor::class, $company)->whereKey($schedules->pluck('doctor_id')->all())->where('is_active', true)->get()->keyBy(fn ($d) => (string) $d->getKey());
        $taken = $this->store->query(Serial::class, $company)->whereIn('schedule_id', $schedules->pluck('id')->all())->whereDate('date', $date->toDateString())
            ->whereIn('status', Serial::HOLDING)->get(['schedule_id'])->countBy(fn ($s) => (string) $s->schedule_id);
        $usual = (int) $this->store->rule('daily_serial_limit', $unit);

        return $schedules->filter(fn (Schedule $s) => $doctors->has((string) $s->doctor_id))->map(function (Schedule $s) use ($chambers, $doctors, $taken, $usual) {
            $limit = $s->serial_limit ?? $usual;
            $used = $taken->get((string) $s->getKey(), 0);

            return [
                'id' => $s->getKey(), 'doctor_id' => $s->doctor_id, 'doctor' => $doctors[(string) $s->doctor_id]->textIn('name'),
                'chamber_id' => $s->chamber_id, 'chamber' => $chambers[(string) $s->chamber_id]->name, 'room' => $chambers[(string) $s->chamber_id]->room,
                'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at, 'limit' => $limit, 'taken' => $used, 'free' => max(0, $limit - $used),
            ];
        })->values()->all();
    }

    /**
     * What a serial with this doctor would cost the patient today: new or follow-up, free or the fee's service.
     *
     * @return array{kind: string, is_free: bool, service_code: ?string, price_minor: ?int, last_visit: ?string}
     */
    public function quote(Organization $company, Organization $unit, Doctor $doctor, string $patientId, CarbonImmutable $date): array
    {
        $finished = $this->store->query(Serial::class, $company)->where('patient_id', $patientId)->where('doctor_id', $doctor->getKey())
            ->where('status', Serial::DONE)->whereDate('date', '<=', $date->toDateString());
        $lastVisit = (clone $finished)->orderByDesc('date')->value('date');
        $lastPaid = (clone $finished)->where('is_free', false)->orderByDesc('date')->value('date');
        $kind = $lastVisit === null ? Serial::NEW : Serial::FOLLOW_UP;
        $freeDays = (int) $this->store->rule('follow_up_free_days', $unit);
        $free = $lastPaid !== null && $freeDays > 0 && CarbonImmutable::parse($lastPaid)->startOfDay()->diffInDays($date, true) <= $freeDays;
        $code = $free ? null : ($kind === Serial::FOLLOW_UP && $doctor->follow_up_service_code ? $doctor->follow_up_service_code : $doctor->fee_service_code);

        return [
            'kind' => $kind, 'is_free' => $free, 'service_code' => $code,
            'price_minor' => $code === null ? null : ($this->charges->services($company)[$code]['price_minor'] ?? null),
            'last_visit' => $lastVisit === null ? null : CarbonImmutable::parse($lastVisit)->toDateString(),
        ];
    }

    /** The quote for a session the unit holds (rules at the chamber's branch). */
    public function quoteFor(Organization $company, Organization $unit, string $scheduleId, string $patientId, CarbonImmutable $date): array
    {
        [, $chamber, $doctor] = $this->session($company, $unit, $scheduleId);

        return $this->quote($company, Organization::query()->find($chamber->unit_id) ?? $unit, $doctor, $patientId, $date);
    }

    /** @return array{0: Schedule, 1: Chamber, 2: Doctor} A session in use at the unit's chambers, with its doctor. */
    private function session(Organization $company, Organization $unit, string $scheduleId): array
    {
        $schedule = $this->store->query(Schedule::class, $company)->whereKey($scheduleId)->where('is_active', true)->first() ?? throw OutpatientException::notFound('session');
        $chamber = $this->store->query(Chamber::class, $company)->whereKey($schedule->chamber_id)->whereIn('unit_id', $this->store->subtreeIds($unit))->where('is_active', true)->first()
            ?? throw OutpatientException::notFound('session');
        $doctor = $this->store->query(Doctor::class, $company)->whereKey($schedule->doctor_id)->where('is_active', true)->first() ?? throw OutpatientException::notFound('doctor');

        return [$schedule, $chamber, $doctor];
    }

    public function book(Organization $company, Organization $unit, string $scheduleId, CarbonImmutable $date, string $patientId, ?string $opId, User $actor): Serial
    {
        if ($opId !== null && ($done = $this->store->query(Serial::class, $company)->where('op_id', $opId)->first()) !== null) {
            return $done;
        }
        if ($date->lt($this->store->today($company))) {
            throw OutpatientException::because('date_passed');
        }
        $patient = $this->patients->find($company, $patientId) ?? throw OutpatientException::notFound('patient');
        [$schedule, $chamber, $doctor] = $this->session($company, $unit, $scheduleId);
        if ($schedule->weekday !== $date->dayOfWeek) {
            throw OutpatientException::because('no_session');
        }
        $chamberUnit = Organization::query()->find($chamber->unit_id) ?? $unit;
        $quote = $this->quote($company, $chamberUnit, $doctor, $patient->id, $date);

        try {
            $serial = $this->store->transaction($company, function () use ($company, $chamberUnit, $schedule, $chamber, $doctor, $date, $patient, $quote, $opId, $actor) {
                $this->store->query(Schedule::class, $company)->whereKey($schedule->getKey())->lockForUpdate()->first();
                $onDay = $this->store->query(Serial::class, $company)->where('schedule_id', $schedule->getKey())->whereDate('date', $date->toDateString());
                if ((clone $onDay)->where('patient_id', $patient->id)->whereIn('status', Serial::HOLDING)->exists()) {
                    throw OutpatientException::because('already_booked', 409);
                }
                $limit = $schedule->serial_limit ?? (int) $this->store->rule('daily_serial_limit', $chamberUnit);
                if ((clone $onDay)->whereIn('status', Serial::HOLDING)->count() >= $limit) {
                    throw OutpatientException::because('session_full', 409, ['limit' => $limit]);
                }
                $serial = new Serial([
                    'organization_id' => $company->getKey(), 'unit_id' => $chamber->unit_id, 'doctor_id' => $doctor->getKey(), 'chamber_id' => $chamber->getKey(),
                    'schedule_id' => $schedule->getKey(), 'date' => $date->toDateString(), 'serial_no' => ((int) (clone $onDay)->max('serial_no')) + 1,
                    'patient_id' => $patient->id, 'kind' => $quote['kind'], 'is_free' => $quote['is_free'], 'booked_by' => $actor->getKey(), 'op_id' => $opId,
                ]);
                $serial->save();
                if (! $quote['is_free']) {
                    $line = $this->charges->add($company, $chamberUnit, $patient->id, 'outpatient', 'serial', $serial->getKey(), $quote['service_code'], 1000, $actor);
                    $serial->forceFill(['bill_id' => $line['bill_id'], 'bill_line_id' => $line['line_id']])->save();
                }
                if ($doctor->user_id !== null) {
                    $this->patients->linkCarer($company, $patient->id, $doctor->user_id, 'outpatient');
                }
                $this->audit->record('outpatient.serial_booked', $serial, new: [
                    'serial_no' => $serial->serial_no, 'date' => $date->toDateString(), 'doctor_id' => $doctor->getKey(), 'kind' => $quote['kind'], 'free' => $quote['is_free'],
                ], actor: $actor, organizationId: $company->getKey());

                return $serial;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same op id from a second tap that raced the first.
            if ($opId !== null && ($done = $this->store->query(Serial::class, $company)->where('op_id', $opId)->first()) !== null) {
                return $done;
            }
            throw $e;
        }
        SerialBooked::dispatch((string) $company->getKey(), (string) $serial->getKey(), (string) $serial->patient_id, (string) $serial->doctor_id);

        return $serial;
    }

    /**
     * Moves a serial on (arrived, in, done, absent, cancelled).
     *
     * @return array{serial: Serial, fee_withdrawn: ?bool}  fee_withdrawn: null when there was no fee, false when the bill is already final.
     */
    public function move(Organization $company, Organization $unit, Serial $serial, string $to, ?string $reason, int $baseVersion, User $actor): array
    {
        $withdrawn = null;
        $serial = $this->store->transaction($company, function () use ($company, $unit, $serial, $to, $reason, $baseVersion, $actor, &$withdrawn) {
            $serial = $this->store->query(Serial::class, $company)->whereKey($serial->getKey())->lockForUpdate()->firstOrFail();
            if ($serial->version !== $baseVersion) {
                throw OutpatientException::versionConflict(['version' => $serial->version, 'status' => $serial->status]);
            }
            if (! in_array($to, Serial::NEXT[$serial->status] ?? [], true)) {
                throw OutpatientException::because('cannot_move', 409, ['from' => $serial->status, 'to' => $to]);
            }
            $this->mayMove($company, $unit, $serial, $to, $actor);
            $from = $serial->status;
            $now = CarbonImmutable::now();
            $serial->status = $to;
            match ($to) {
                Serial::ARRIVED => $serial->arrived_at ??= $now,
                Serial::IN => $serial->called_at = $now,
                Serial::DONE => $serial->finished_at = $now,
                Serial::CANCELLED => $serial->forceFill(['cancelled_at' => $now, 'cancel_reason' => $reason]),
                default => null,
            };
            $serial->version++;
            $serial->save();
            if ($to === Serial::CANCELLED && $serial->bill_line_id !== null) {
                $withdrawn = $this->charges->withdraw($company, 'outpatient', 'serial', (string) $serial->getKey(), $actor);
            }
            $this->audit->record('outpatient.serial_moved', $serial, old: ['status' => $from], new: [
                'status' => $to, 'serial_no' => $serial->serial_no, 'date' => $serial->date->toDateString(), 'fee_withdrawn' => $withdrawn,
            ], actor: $actor, organizationId: $company->getKey());

            return $serial;
        });

        return ['serial' => $serial, 'fee_withdrawn' => $withdrawn];
    }

    /** Whether the person is a doctor limited to their own patients here (patients.doctor_sees = own). */
    public function onlyOwn(Organization $unit, User $user): bool
    {
        return $this->store->ruleOf('patients.doctor_sees', $unit) === 'own'
            && Gate::forUser($user)->allows('outpatient.consult', $unit)
            && ! Gate::forUser($user)->any(['outpatient.book', 'outpatient.manage'], $unit);
    }

    /** @return list<string> Doctors with sessions in use at the unit's chambers and below. */
    public function sessionsDoctorIds(Organization $company, Organization $unit): array
    {
        $chambers = $this->store->query(Chamber::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))->pluck('id')->all();

        return $this->store->query(Schedule::class, $company)->where('is_active', true)->whereIn('chamber_id', $chambers)
            ->distinct()->pluck('doctor_id')->map(fn ($id) => (string) $id)->all();
    }

    /** @return Collection<int, string> The doctors signed in as this person. */
    public function doctorsOf(Organization $company, User $user): Collection
    {
        return $this->store->query(Doctor::class, $company)->where('user_id', $user->getKey())->pluck('id')->map(fn ($id) => (string) $id);
    }

    /**
     * The desk (outpatient.book) moves any serial; calling in and finishing is also the doctor's own
     * (outpatient.consult, the serial's doctor signed in as them).
     */
    private function mayMove(Organization $company, Organization $unit, Serial $serial, string $to, User $actor): void
    {
        if (Gate::forUser($actor)->allows('outpatient.book', $unit)) {
            return;
        }
        $own = $this->doctorsOf($company, $actor)->contains((string) $serial->doctor_id);
        if (in_array($to, [Serial::IN, Serial::DONE], true) && $own && Gate::forUser($actor)->allows('outpatient.consult', $unit)) {
            return;
        }
        throw OutpatientException::because($own ? 'desk_only' : 'own_patients_only', 403);
    }
}
