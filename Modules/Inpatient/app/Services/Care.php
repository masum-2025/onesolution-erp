<?php

namespace Modules\Inpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Diagnostics\Contracts\TestOrders;
use Modules\Inpatient\Events\TestsOrdered;
use Modules\Inpatient\Exceptions\InpatientException;
use Modules\Inpatient\Models\Admission;
use Modules\Inpatient\Models\NursingNote;
use Modules\Inpatient\Models\Round;
use Modules\Inpatient\Models\TestOrder;

/**
 * Care during a stay, all append-only and written only while the patient is in:
 *
 * - round notes by a doctor (inpatient.round),
 * - nursing notes with vitals and the medicines given (inpatient.nurse),
 * - tests sent to the lab as one order through Diagnostics' TestOrders
 *   (billed there; only with the lab on).
 * Each write carries an op id, so a retry never writes twice. Audited with
 * counts only, never what was written or measured.
 */
class Care
{
    public function __construct(private InpatientStore $store, private ModuleResolver $modules, private AuditLogger $audit) {}

    public function labOn(Organization $company): bool
    {
        return $this->modules->isEnabled('diagnostics', $company);
    }

    public function addRound(Organization $company, Admission $admission, string $note, ?string $opId, User $actor): Round
    {
        return $this->once($company, Round::class, $opId, function () use ($company, $admission, $note, $opId, $actor) {
            $this->mustBeIn($admission);
            $round = new Round([
                'organization_id' => $company->getKey(), 'admission_id' => $admission->getKey(), 'doctor_user_id' => $actor->getKey(),
                'note' => trim($note), 'noted_at' => CarbonImmutable::now(), 'op_id' => $opId,
            ]);
            $round->save();
            $this->audit->record('inpatient.round_added', $round, new: ['number' => $admission->number], actor: $actor, organizationId: $company->getKey());

            return $round;
        });
    }

    /**
     * @param  array{vitals?: ?array<string, mixed>, note?: ?string, medicines_given?: ?list<array<string, mixed>>, op_id?: ?string}  $data
     */
    public function addNursingNote(Organization $company, Admission $admission, array $data, User $actor): NursingNote
    {
        $vitals = array_filter($data['vitals'] ?? [], fn ($value) => $value !== null && $value !== '');
        $medicines = array_values(array_filter($data['medicines_given'] ?? [], fn ($row) => trim((string) ($row['name'] ?? '')) !== ''));
        $note = trim((string) ($data['note'] ?? ''));
        if ($vitals === [] && $medicines === [] && $note === '') {
            throw ValidationException::withMessages(['note' => __('inpatient::messages.validation.empty_note')]);
        }
        $opId = $data['op_id'] ?? null;

        return $this->once($company, NursingNote::class, $opId, function () use ($company, $admission, $vitals, $medicines, $note, $opId, $actor) {
            $this->mustBeIn($admission);
            $row = new NursingNote([
                'organization_id' => $company->getKey(), 'admission_id' => $admission->getKey(), 'vitals' => $vitals ?: null, 'note' => $note ?: null,
                'medicines_given' => $medicines ?: null, 'noted_by' => $actor->getKey(), 'noted_at' => CarbonImmutable::now(), 'op_id' => $opId,
            ]);
            $row->save();
            $this->audit->record('inpatient.nursing_note_added', $row, new: ['number' => $admission->number, 'vitals' => count($vitals), 'medicines' => count($medicines)], actor: $actor, organizationId: $company->getKey());

            return $row;
        });
    }

    /**
     * @param  list<string>  $codes  The lab's test codes.
     */
    public function orderTests(Organization $company, Admission $admission, array $codes, ?string $opId, User $actor): TestOrder
    {
        if (! $this->labOn($company)) {
            throw InpatientException::because('lab_off', 403);
        }
        $codes = array_values(array_unique($codes));
        $known = array_column(app(TestOrders::class)->catalogue($company), 'code');
        foreach ($codes as $index => $code) {
            if (! in_array($code, $known, true)) {
                throw ValidationException::withMessages(["codes.{$index}" => __('inpatient::messages.validation.test')]);
            }
        }
        $order = $this->once($company, TestOrder::class, $opId, function () use ($company, $admission, $codes, $opId, $actor) {
            $this->mustBeIn($admission);
            $row = new TestOrder([
                'organization_id' => $company->getKey(), 'admission_id' => $admission->getKey(), 'codes' => $codes,
                'ordered_by' => $actor->getKey(), 'ordered_at' => CarbonImmutable::now(), 'op_id' => $opId,
            ]);
            $row->save();
            $unit = Organization::query()->find($admission->unit_id) ?? $company;
            $placed = app(TestOrders::class)->place($company, $unit, $admission->patient_id, $codes, 'inpatient', (string) $row->getKey(), $actor);
            $row->forceFill(['lab_order_id' => $placed['order_id'], 'lab_order_number' => $placed['number']])->save();
            $this->audit->record('inpatient.tests_ordered', $row, new: ['number' => $admission->number, 'tests' => count($codes), 'order' => $placed['number']], actor: $actor, organizationId: $company->getKey());

            return $row;
        });
        if ($order->wasRecentlyCreated) {
            TestsOrdered::dispatch((string) $company->getKey(), (string) $admission->getKey(), (string) $admission->patient_id, (string) $order->lab_order_id);
        }

        return $order;
    }

    private function mustBeIn(Admission $admission): void
    {
        if ($admission->status !== Admission::ADMITTED) {
            throw InpatientException::because('not_admitted', 409);
        }
    }

    /**
     * Writes a row once per op id: a retry returns the row already written.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  callable(): T  $write
     * @return T
     */
    private function once(Organization $company, string $model, ?string $opId, callable $write): Model
    {
        if ($opId !== null && ($done = $this->store->query($model, $company)->where('op_id', $opId)->first()) !== null) {
            return $done;
        }
        try {
            return $this->store->transaction($company, $write);
        } catch (UniqueConstraintViolationException $e) {
            if ($opId !== null && ($done = $this->store->query($model, $company)->where('op_id', $opId)->first()) !== null) {
                return $done;
            }
            throw $e;
        }
    }
}
