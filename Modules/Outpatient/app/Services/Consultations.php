<?php

namespace Modules\Outpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Diagnostics\Contracts\TestOrders;
use Modules\Outpatient\Events\ConsultationDone;
use Modules\Outpatient\Events\PrescriptionWritten;
use Modules\Outpatient\Events\TestsAdvised;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Models\AdvisedTest;
use Modules\Outpatient\Models\Consultation;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\PrescriptionItem;
use Modules\Outpatient\Models\Serial;
use Modules\Patients\Contracts\PatientDirectory;
use Modules\Pharmacy\Contracts\MedicineCatalogue;

/**
 * What the doctor writes for a serial.
 *
 * - Written by someone with outpatient.consult: the serial's doctor when the
 *   doctor has a sign-in, anyone with the permission for a doctor without one.
 * - A draft is saved with its version (two people writing at once get 409).
 * - Finishing makes it final: the serial is done, the patient's history gets
 *   a line (no diagnosis in it), and the events go out. A finished one is
 *   never changed: an amendment (with a reason) is the next revision, and the
 *   one it replaces is kept and marked superseded once the new one finishes.
 * - Medicines are picked from the pharmacy (when on) or written; tests from
 *   the lab (when on) or written. Sending tests to the lab makes one order
 *   through the lab's TestOrders (billed there as usual).
 * Audited with counts only, never what was written.
 */
class Consultations
{
    public function __construct(
        private OutpatientStore $store,
        private PatientDirectory $patients,
        private ModuleResolver $modules,
        private AuditLogger $audit,
    ) {}

    /** The latest revision of a serial's consultation (draft or finished). */
    public function current(Organization $company, Serial $serial): ?Consultation
    {
        return $this->store->query(Consultation::class, $company)->where('serial_id', $serial->getKey())->orderByDesc('revision')->first();
    }

    /** The finished revision in force (the one the patient's prescription shows). */
    public function inForce(Organization $company, Serial $serial): ?Consultation
    {
        return $this->store->query(Consultation::class, $company)->where('serial_id', $serial->getKey())->where('status', Consultation::FINISHED)
            ->whereNull('superseded_at')->orderByDesc('revision')->first();
    }

    /** Whether medicines come from the pharmacy's list, and tests from the lab's. */
    public function pharmacyOn(Organization $company): bool
    {
        return $this->modules->isEnabled('pharmacy', $company);
    }

    public function labOn(Organization $company): bool
    {
        return $this->modules->isEnabled('diagnostics', $company);
    }

    /** The serial's doctor writes; a doctor without a sign-in is written for by anyone who consults. */
    public function mayWrite(Organization $company, Organization $unit, Serial $serial, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('outpatient.consult', $unit)) {
            throw OutpatientException::because('consult_only', 403);
        }
        $doctorUser = $this->store->query(Doctor::class, $company)->whereKey($serial->doctor_id)->value('user_id');
        if ($doctorUser !== null && (string) $doctorUser !== (string) $actor->getKey()) {
            throw OutpatientException::because('own_patients_only', 403);
        }
    }

    /**
     * @param  array<string, mixed>  $data  Validated by ConsultationRequest.
     */
    public function save(Organization $company, Organization $unit, Serial $serial, array $data, ?int $baseVersion, User $actor): Consultation
    {
        $this->mayWrite($company, $unit, $serial, $actor);
        if ($serial->status === Serial::CANCELLED) {
            throw OutpatientException::because('serial_cancelled', 409);
        }
        $medicines = $this->medicines($company, $data['medicines'] ?? []);
        $tests = $this->tests($company, $data['tests'] ?? []);

        return $this->store->transaction($company, function () use ($company, $serial, $data, $baseVersion, $actor, $medicines, $tests) {
            $current = $this->store->query(Consultation::class, $company)->where('serial_id', $serial->getKey())->orderByDesc('revision')->lockForUpdate()->first();
            if ($current === null) {
                $current = new Consultation([
                    'organization_id' => $company->getKey(), 'unit_id' => $serial->unit_id, 'serial_id' => $serial->getKey(),
                    'patient_id' => $serial->patient_id, 'doctor_id' => $serial->doctor_id, 'written_by' => $actor->getKey(),
                ]);
            } elseif ($current->status === Consultation::FINISHED) {
                throw OutpatientException::because('already_finished', 409);
            } elseif ($current->version !== $baseVersion) {
                throw OutpatientException::versionConflict(['version' => $current->version]);
            } else {
                $current->version++;
            }
            $current->fill([
                'complaints' => $data['complaints'] ?? null, 'diagnosis' => $data['diagnosis'] ?? null, 'advice' => $data['advice'] ?? null,
                'follow_up_on' => $data['follow_up_on'] ?? null,
                'findings' => array_filter(array_intersect_key($data['findings'] ?? [], array_flip(Consultation::FINDINGS)), fn ($value) => $value !== null && $value !== '') ?: null,
            ]);
            $current->save();
            $this->replaceLines($company, $current, $medicines, $tests);

            return $current;
        });
    }

    public function finish(Organization $company, Organization $unit, Serial $serial, int $baseVersion, User $actor): Consultation
    {
        $this->mayWrite($company, $unit, $serial, $actor);
        $done = $this->store->transaction($company, function () use ($company, $serial, $baseVersion, $actor) {
            $current = $this->store->query(Consultation::class, $company)->where('serial_id', $serial->getKey())->orderByDesc('revision')->lockForUpdate()->first();
            if ($current === null) {
                throw OutpatientException::because('nothing_written');
            }
            if ($current->status === Consultation::FINISHED) {
                throw OutpatientException::because('already_finished', 409);
            }
            if ($current->version !== $baseVersion) {
                throw OutpatientException::versionConflict(['version' => $current->version]);
            }
            $medicines = $this->store->query(PrescriptionItem::class, $company)->where('consultation_id', $current->getKey())->count();
            $tests = $this->store->query(AdvisedTest::class, $company)->where('consultation_id', $current->getKey())->count();
            if (trim((string) $current->diagnosis) === '' && trim((string) $current->advice) === '' && $medicines === 0 && $tests === 0) {
                throw OutpatientException::because('nothing_written');
            }
            $now = CarbonImmutable::now();
            $current->forceFill(['status' => Consultation::FINISHED, 'finished_at' => $now, 'finished_by' => $actor->getKey(), 'version' => $current->version + 1])->save();
            $this->store->query(Consultation::class, $company)->where('serial_id', $serial->getKey())->where('revision', '<', $current->revision)
                ->where('status', Consultation::FINISHED)->whereNull('superseded_at')->update(['superseded_at' => $now]);
            $locked = $this->store->query(Serial::class, $company)->whereKey($serial->getKey())->lockForUpdate()->first();
            if (in_array($locked->status, [Serial::BOOKED, Serial::ARRIVED, Serial::IN, Serial::ABSENT], true)) {
                $locked->forceFill(['status' => Serial::DONE, 'finished_at' => $now, 'arrived_at' => $locked->arrived_at ?? $now, 'version' => $locked->version + 1])->save();
            }
            $doctor = $this->store->query(Doctor::class, $company)->find($serial->doctor_id);
            $this->patients->addToTimeline($company, $serial->patient_id, 'outpatient', 'consultation', (string) $serial->getKey(), [
                'en' => __('outpatient::messages.timeline.seen', ['doctor' => $doctor?->textIn('name', 'en') ?? ''], 'en'),
                'bn' => __('outpatient::messages.timeline.seen', ['doctor' => $doctor?->textIn('name', 'bn') ?? ''], 'bn'),
            ], $now);
            $this->audit->record('outpatient.consultation_finished', $current, new: [
                'serial_no' => $serial->serial_no, 'revision' => $current->revision, 'medicines' => $medicines, 'tests' => $tests,
            ], actor: $actor, organizationId: $company->getKey());

            return [$current, $medicines, $tests];
        });
        [$consultation, $medicines, $tests] = $done;
        $ids = [(string) $company->getKey(), (string) $consultation->getKey(), (string) $serial->patient_id, (string) $serial->doctor_id];
        ConsultationDone::dispatch(...$ids);
        if ($medicines > 0) {
            PrescriptionWritten::dispatch(...$ids);
        }
        if ($tests > 0) {
            TestsAdvised::dispatch(...$ids);
        }

        return $consultation;
    }

    /** A finished consultation put right: the next revision, a draft with the same lines, and the reason. */
    public function amend(Organization $company, Organization $unit, Serial $serial, string $reason, User $actor): Consultation
    {
        $this->mayWrite($company, $unit, $serial, $actor);

        return $this->store->transaction($company, function () use ($company, $serial, $reason, $actor) {
            $current = $this->store->query(Consultation::class, $company)->where('serial_id', $serial->getKey())->orderByDesc('revision')->lockForUpdate()->first();
            if ($current === null || $current->status !== Consultation::FINISHED) {
                throw OutpatientException::because('not_finished', 409);
            }
            $next = new Consultation([
                ...array_intersect_key($current->getAttributes(), array_flip(['organization_id', 'unit_id', 'serial_id', 'patient_id', 'doctor_id', 'complaints', 'diagnosis', 'advice', 'follow_up_on'])),
                'findings' => $current->findings, 'revision' => $current->revision + 1, 'amend_reason' => $reason, 'written_by' => $actor->getKey(),
            ]);
            $next->save();
            foreach ($this->store->query(PrescriptionItem::class, $company)->where('consultation_id', $current->getKey())->get() as $item) {
                (new PrescriptionItem([...array_intersect_key($item->getAttributes(), array_flip(['organization_id', 'position', 'medicine_id', 'medicine_text', 'dose', 'duration', 'timing', 'note'])), 'consultation_id' => $next->getKey()]))->save();
            }
            foreach ($this->store->query(AdvisedTest::class, $company)->where('consultation_id', $current->getKey())->get() as $test) {
                (new AdvisedTest([...array_intersect_key($test->getAttributes(), array_flip(['organization_id', 'position', 'test_code', 'test_text'])), 'consultation_id' => $next->getKey()]))->save();
            }
            $this->audit->record('outpatient.consultation_amended', $next, new: ['serial_no' => $serial->serial_no, 'revision' => $next->revision], actor: $actor, organizationId: $company->getKey());

            return $next;
        });
    }

    /** The advised tests with a lab code go to the lab as one order (once), billed there. */
    public function sendTests(Organization $company, Organization $unit, Serial $serial, User $actor): Consultation
    {
        $this->mayWrite($company, $unit, $serial, $actor);
        if (! $this->labOn($company)) {
            throw OutpatientException::because('lab_off', 403);
        }
        $consultation = $this->inForce($company, $serial) ?? throw OutpatientException::because('not_finished', 409);
        if ($consultation->lab_order_id !== null) {
            return $consultation;
        }
        $codes = $this->store->query(AdvisedTest::class, $company)->where('consultation_id', $consultation->getKey())->whereNotNull('test_code')->orderBy('position')->pluck('test_code')->unique()->values()->all();
        if ($codes === []) {
            throw OutpatientException::because('no_lab_tests');
        }
        $chamberUnit = Organization::query()->find($serial->unit_id) ?? $unit;
        $order = app(TestOrders::class)->place($company, $chamberUnit, $serial->patient_id, $codes, 'outpatient', (string) $consultation->getKey(), $actor);
        $consultation->forceFill(['lab_order_id' => $order['order_id']])->save();
        $this->audit->record('outpatient.tests_sent', $consultation, new: ['serial_no' => $serial->serial_no, 'tests' => count($codes), 'order' => $order['number']], actor: $actor, organizationId: $company->getKey());

        return $consultation;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>  medicine_id only for the pharmacy's medicines in use
     */
    private function medicines(Organization $company, array $rows): array
    {
        $ids = array_values(array_filter(array_column($rows, 'medicine_id')));
        $known = $ids !== [] && $this->pharmacyOn($company) ? app(MedicineCatalogue::class)->labels($company, $ids) : [];
        foreach ($rows as $index => $row) {
            if (($row['medicine_id'] ?? null) !== null && ! array_key_exists($row['medicine_id'], $known)) {
                throw ValidationException::withMessages(["medicines.{$index}.medicine_id" => __('outpatient::messages.validation.medicine')]);
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function tests(Organization $company, array $rows): array
    {
        $codes = array_values(array_filter(array_column($rows, 'test_code')));
        $known = $codes !== [] && $this->labOn($company) ? array_column(app(TestOrders::class)->catalogue($company), 'code') : [];
        foreach ($rows as $index => $row) {
            if (($row['test_code'] ?? null) !== null && ! in_array($row['test_code'], $known, true)) {
                throw ValidationException::withMessages(["tests.{$index}.test_code" => __('outpatient::messages.validation.test')]);
            }
        }

        return $rows;
    }

    private function replaceLines(Organization $company, Consultation $consultation, array $medicines, array $tests): void
    {
        $this->store->query(PrescriptionItem::class, $company)->where('consultation_id', $consultation->getKey())->delete();
        $this->store->query(AdvisedTest::class, $company)->where('consultation_id', $consultation->getKey())->delete();
        foreach (array_values($medicines) as $position => $row) {
            (new PrescriptionItem([
                'organization_id' => $company->getKey(), 'consultation_id' => $consultation->getKey(), 'position' => $position + 1,
                'medicine_id' => $row['medicine_id'] ?? null, 'medicine_text' => trim($row['medicine_text']), 'dose' => $row['dose'] ?? null,
                'duration' => $row['duration'] ?? null, 'timing' => $row['timing'] ?? null, 'note' => $row['note'] ?? null,
            ]))->save();
        }
        foreach (array_values($tests) as $position => $row) {
            (new AdvisedTest([
                'organization_id' => $company->getKey(), 'consultation_id' => $consultation->getKey(), 'position' => $position + 1,
                'test_code' => $row['test_code'] ?? null, 'test_text' => trim($row['test_text']),
            ]))->save();
        }
    }
}
