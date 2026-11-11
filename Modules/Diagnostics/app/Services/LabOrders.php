<?php

namespace Modules\Diagnostics\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Diagnostics\Events\ReportReady;
use Modules\Diagnostics\Events\ResultVerified;
use Modules\Diagnostics\Events\SampleCollected;
use Modules\Diagnostics\Events\TestsOrdered;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Models\Delivery;
use Modules\Diagnostics\Models\Order;
use Modules\Diagnostics\Models\OrderTest;
use Modules\Diagnostics\Models\Referrer;
use Modules\Diagnostics\Models\Sample;
use Modules\Diagnostics\Models\Test;
use Modules\Diagnostics\Models\TestResult;
use Modules\Diagnostics\Models\Verification;
use Modules\PatientBilling\Contracts\PatientCharges;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * A test order through the lab:
 *
 * 1. Ordered: each test goes on the patient's bill (Patient billing's
 *    PatientCharges, one line per test, priced by the test's service code).
 * 2. Collected: one barcoded sample per kind of sample; tests that need none
 *    (imaging, ECG) are ready at once. A rejected sample sends its tests back.
 * 3. Resulted: values entered for a collected test, flagged against the
 *    normal range for the patient's sex and age. Each submission is kept
 *    (amendment 1, 2, …); entering after verification makes a new amendment.
 * 4. Verified: signed by someone other than who entered the results (rule
 *    diagnostics.require_verification; when off, results count as verified).
 * 5. Delivered: only once every test is verified.
 *
 * Verified reports go into the patient's history. Every step is audited
 * (numbers and codes, never values).
 */
class LabOrders
{
    public function __construct(
        private DiagnosticsStore $store,
        private TestSetup $setup,
        private PatientDirectory $patients,
        private PatientCharges $charges,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $testIds
     * @param  array{module: string, id: string}|null  $source  Another module's order (outpatient, inpatient).
     */
    public function place(Organization $company, Organization $unit, string $patientId, array $testIds, ?string $referrerId, ?string $notes, ?string $opId, User $actor, ?array $source = null): Order
    {
        if ($opId !== null && ($done = $this->store->query(Order::class, $company)->where('op_id', $opId)->first()) !== null) {
            return $done;
        }
        if ($source !== null && ($done = $this->store->query(Order::class, $company)->where('source_module', $source['module'])->where('source_id', $source['id'])->first()) !== null) {
            return $done;
        }
        $patient = $this->patients->find($company, $patientId) ?? throw DiagnosticsException::notFound('patient');
        $tests = $this->store->query(Test::class, $company)->whereKey(array_values(array_unique($testIds)))->where('is_active', true)->get();
        if ($tests->count() !== count(array_unique($testIds)) || $tests->isEmpty()) {
            throw DiagnosticsException::because('test_unknown');
        }
        if ($referrerId !== null && ! $this->store->query(Referrer::class, $company)->whereKey($referrerId)->where('is_active', true)->exists()) {
            throw DiagnosticsException::notFound('referrer');
        }

        $order = $this->store->transaction($company, function () use ($company, $unit, $patient, $tests, $referrerId, $notes, $opId, $actor, $source) {
            $order = new Order([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'number' => $this->store->nextNumber($company, 'order'),
                'patient_id' => $patient->id, 'referrer_id' => $referrerId, 'notes' => $notes, 'created_by' => $actor->getKey(), 'op_id' => $opId,
                'source_module' => $source['module'] ?? null, 'source_id' => $source['id'] ?? null,
            ]);
            $order->save();
            foreach ($tests as $test) {
                $line = new OrderTest(['organization_id' => $company->getKey(), 'order_id' => $order->getKey(), 'test_id' => $test->getKey()]);
                $line->save();
                $charged = $this->charges->add($company, $unit, $patient->id, 'diagnostics', 'order_test', $line->getKey(), $test->service_code, 1000, $actor);
                $order->bill_id ??= $charged['bill_id'];
            }
            // Tests without a sample (imaging, ECG) are ready for results at once.
            $this->store->query(OrderTest::class, $company)->where('order_id', $order->getKey())
                ->whereIn('test_id', $tests->where('sample_type', 'none')->modelKeys())->update(['status' => OrderTest::COLLECTED]);
            $this->refreshStatus($company, $order);
            $this->audit->record('diagnostics.order_placed', $order, new: ['number' => $order->number, 'tests' => $tests->pluck('code')->values()->all()], actor: $actor, organizationId: $company->getKey());

            return $order;
        });
        TestsOrdered::dispatch($company->getKey(), $order->getKey(), $order->patient_id, $order->referrer_id);

        return $order;
    }

    /**
     * One barcoded sample for each kind of sample the waiting tests need.
     *
     * @return list<Sample>
     */
    public function collect(Organization $company, Order $order, User $actor): array
    {
        $samples = $this->store->transaction($company, function () use ($company, $order, $actor) {
            $order = $this->lock($company, $order);
            $waiting = $this->store->query(OrderTest::class, $company)->where('order_id', $order->getKey())->where('status', OrderTest::ORDERED)->get();
            if ($waiting->isEmpty()) {
                throw DiagnosticsException::because('nothing_to_collect', 409);
            }
            $types = $this->store->query(Test::class, $company)->whereKey($waiting->pluck('test_id')->all())->pluck('sample_type', 'id');
            $samples = [];
            foreach ($waiting->groupBy(fn (OrderTest $line) => $types[$line->test_id] ?? 'other') as $type => $lines) {
                $sample = new Sample([
                    'organization_id' => $company->getKey(), 'barcode' => $this->store->nextNumber($company, 'sample'), 'order_id' => $order->getKey(),
                    'patient_id' => $order->patient_id, 'sample_type' => $type, 'collected_by' => $actor->getKey(), 'collected_at' => CarbonImmutable::now(),
                ]);
                $sample->save();
                $this->store->query(OrderTest::class, $company)->whereKey($lines->modelKeys())->update(['status' => OrderTest::COLLECTED, 'sample_id' => $sample->getKey()]);
                $samples[] = $sample;
            }
            $hours = $this->store->query(Test::class, $company)->whereKey($waiting->pluck('test_id')->all())->pluck('turnaround_hours')
                ->map(fn ($value) => $value ?? (int) $this->store->rule('report_turnaround_hours', $company))->max();
            $order->due_at = CarbonImmutable::now()->addHours((int) $hours);
            $this->refreshStatus($company, $order);
            $this->audit->record('diagnostics.samples_collected', $order, new: ['number' => $order->number, 'barcodes' => array_map(fn (Sample $sample) => $sample->barcode, $samples)],
                actor: $actor, organizationId: $company->getKey());

            return $samples;
        });
        foreach ($samples as $sample) {
            SampleCollected::dispatch($company->getKey(), $sample->order_id, $sample->getKey());
        }

        return $samples;
    }

    /** A sample that cannot be used (clotted, too little): its tests wait for a new one. */
    public function rejectSample(Organization $company, Sample $sample, string $reason, User $actor): Sample
    {
        return $this->store->transaction($company, function () use ($company, $sample, $reason, $actor) {
            $sample = $this->store->query(Sample::class, $company)->whereKey($sample->getKey())->lockForUpdate()->firstOrFail();
            if ($sample->rejected_at !== null) {
                throw DiagnosticsException::because('sample_rejected', 409);
            }
            $lines = $this->store->query(OrderTest::class, $company)->where('sample_id', $sample->getKey())->get();
            if ($lines->contains(fn (OrderTest $line) => $line->status !== OrderTest::COLLECTED)) {
                throw DiagnosticsException::because('sample_has_results', 409);
            }
            $sample->fill(['rejected_reason' => $reason, 'rejected_by' => $actor->getKey(), 'rejected_at' => CarbonImmutable::now()])->save();
            $this->store->query(OrderTest::class, $company)->whereKey($lines->modelKeys())->update(['status' => OrderTest::ORDERED, 'sample_id' => null]);
            $order = $this->store->query(Order::class, $company)->findOrFail($sample->order_id);
            $this->refreshStatus($company, $order);
            $this->audit->record('diagnostics.sample_rejected', $sample, new: ['barcode' => $sample->barcode], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $sample;
        });
    }

    /**
     * Values for a collected test (parameter id => text), flagged; a new amendment each time.
     *
     * @param  array<string, string|null>  $values
     */
    public function saveResults(Organization $company, Organization $unit, OrderTest $line, array $values, int $baseVersion, User $actor): OrderTest
    {
        $needsCheck = (bool) $this->store->rule('require_verification', $unit);

        $line = $this->store->transaction($company, function () use ($company, $line, $values, $baseVersion, $actor, $needsCheck) {
            $line = $this->store->query(OrderTest::class, $company)->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            if ($line->status === OrderTest::ORDERED) {
                throw DiagnosticsException::because('not_collected', 409);
            }
            if ($line->version !== $baseVersion) {
                throw DiagnosticsException::versionConflict(['version' => $line->version]);
            }
            $order = $this->store->query(Order::class, $company)->findOrFail($line->order_id);
            $patient = $this->patients->find($company, $order->patient_id);
            $ageDays = $patient?->dateOfBirth === null ? null : (int) CarbonImmutable::parse($patient->dateOfBirth)->diffInDays($this->store->today($company));
            $parameters = collect($this->setup->of($company, $line->test_id))->keyBy('id');
            $unknown = array_diff(array_keys($values), $parameters->keys()->all());
            if ($unknown !== []) {
                throw ValidationException::withMessages(['values' => __('diagnostics::messages.validation.unknown_parameter')]);
            }
            $amendment = $line->amendment + 1;
            $now = CarbonImmutable::now();
            $errors = [];
            foreach ($parameters as $id => $parameter) {
                $raw = $values[$id] ?? null;
                $value = $raw === null || trim((string) $raw) === '' ? null : trim((string) $raw);
                if ($value !== null && $parameter['result_type'] === 'number') {
                    $value = ResultFlags::normalised($value, (int) $parameter['decimals']) ?? ($errors["values.{$id}"] = __('diagnostics::messages.validation.number'));
                } elseif ($value !== null && $parameter['result_type'] === 'choice' && ! in_array($value, $parameter['choices'], true)) {
                    $errors["values.{$id}"] = __('diagnostics::messages.validation.choice');
                }
                if (isset($errors["values.{$id}"])) {
                    continue;
                }
                $range = ResultFlags::rangeFor($parameter['ranges'], $patient?->sex, $ageDays);
                (new TestResult([
                    'organization_id' => $company->getKey(), 'order_test_id' => $line->getKey(), 'parameter_id' => $id, 'amendment' => $amendment,
                    'value' => $value, 'flag' => ResultFlags::flag($parameter['result_type'], $value, $range), 'entered_by' => $actor->getKey(), 'entered_at' => $now,
                ]))->save();
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $line->fill(['amendment' => $amendment, 'resulted_by' => $actor->getKey(), 'resulted_at' => $now, 'send_back_note' => null,
                'status' => $needsCheck ? OrderTest::RESULTED : OrderTest::VERIFIED]);
            $line->version++;
            $line->save();
            if (! $needsCheck) {
                (new Verification(['organization_id' => $company->getKey(), 'order_test_id' => $line->getKey(), 'amendment' => $amendment, 'verified_by' => $actor->getKey(), 'verified_at' => $now]))->save();
            }
            $this->audit->record('diagnostics.results_entered', $order, new: ['number' => $order->number, 'test_id' => $line->test_id, 'amendment' => $amendment], actor: $actor, organizationId: $company->getKey());
            $this->refreshStatus($company, $order);

            return $line;
        });
        $this->afterVerified($company, $line);

        return $line;
    }

    /** Signed by someone other than who entered the results. */
    public function verify(Organization $company, OrderTest $line, ?string $note, User $actor): OrderTest
    {
        $line = $this->store->transaction($company, function () use ($company, $line, $note, $actor) {
            $line = $this->store->query(OrderTest::class, $company)->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            if ($line->status !== OrderTest::RESULTED) {
                throw DiagnosticsException::because('not_resulted', 409);
            }
            if ($line->resulted_by === $actor->getKey()) {
                throw DiagnosticsException::because('own_results', 403);
            }
            (new Verification(['organization_id' => $company->getKey(), 'order_test_id' => $line->getKey(), 'amendment' => $line->amendment, 'verified_by' => $actor->getKey(),
                'verified_at' => CarbonImmutable::now(), 'note' => $note]))->save();
            $line->status = OrderTest::VERIFIED;
            $line->version++;
            $line->save();
            $order = $this->store->query(Order::class, $company)->findOrFail($line->order_id);
            $this->audit->record('diagnostics.results_verified', $order, new: ['number' => $order->number, 'test_id' => $line->test_id, 'amendment' => $line->amendment], actor: $actor, organizationId: $company->getKey());
            $this->refreshStatus($company, $order);

            return $line;
        });
        $this->afterVerified($company, $line);

        return $line;
    }

    /** Back to the bench with a note (the results stay on record). */
    public function sendBack(Organization $company, OrderTest $line, string $note, User $actor): OrderTest
    {
        return $this->store->transaction($company, function () use ($company, $line, $note, $actor) {
            $line = $this->store->query(OrderTest::class, $company)->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            if ($line->status !== OrderTest::RESULTED) {
                throw DiagnosticsException::because('not_resulted', 409);
            }
            $line->fill(['status' => OrderTest::COLLECTED, 'send_back_note' => $note]);
            $line->version++;
            $line->save();
            $order = $this->store->query(Order::class, $company)->findOrFail($line->order_id);
            $this->audit->record('diagnostics.results_sent_back', $order, new: ['number' => $order->number, 'test_id' => $line->test_id], reason: $note, actor: $actor, organizationId: $company->getKey());
            $this->refreshStatus($company, $order);

            return $line;
        });
    }

    public function deliver(Organization $company, Order $order, string $channel, ?User $actor): Order
    {
        return $this->store->transaction($company, function () use ($company, $order, $channel, $actor) {
            $order = $this->lock($company, $order);
            if (! in_array($order->status, ['verified', 'delivered'], true)) {
                throw DiagnosticsException::because('not_verified', 409);
            }
            (new Delivery(['organization_id' => $company->getKey(), 'order_id' => $order->getKey(), 'channel' => $channel, 'sent_by' => $actor?->getKey(), 'sent_at' => CarbonImmutable::now()]))->save();
            $order->fill(['status' => 'delivered', 'delivered_at' => $order->delivered_at ?? CarbonImmutable::now()]);
            $order->version++;
            $order->save();
            $this->audit->record('diagnostics.report_delivered', $order, new: ['number' => $order->number, 'channel' => $channel], actor: $actor, organizationId: $company->getKey());

            return $order;
        });
    }

    /** The order's stage is its slowest test's (delivered stays delivered while all are verified). */
    public function refreshStatus(Organization $company, Order $order): void
    {
        $statuses = $this->store->query(OrderTest::class, $company)->where('order_id', $order->getKey())->pluck('status')->all();
        $slowest = collect([OrderTest::ORDERED, OrderTest::COLLECTED, OrderTest::RESULTED, OrderTest::VERIFIED])->first(fn ($status) => in_array($status, $statuses, true)) ?? OrderTest::VERIFIED;
        $status = $slowest === OrderTest::VERIFIED && $order->delivered_at !== null ? 'delivered' : $slowest;
        if ($order->status !== $status || $order->isDirty()) {
            $order->status = $status;
            $order->version++;
            $order->save();
        }
    }

    /** When the whole order is verified: the patient's history and the report-ready event (once per amendment set). */
    private function afterVerified(Organization $company, OrderTest $line): void
    {
        if ($line->status !== OrderTest::VERIFIED) {
            return;
        }
        $order = $this->store->query(Order::class, $company)->findOrFail($line->order_id);
        ResultVerified::dispatch($company->getKey(), $order->getKey(), $line->getKey(), $line->amendment);
        if (! in_array($order->status, ['verified', 'delivered'], true)) {
            return;
        }
        $this->patients->addToTimeline($company, $order->patient_id, 'diagnostics', 'report', $order->getKey(), [
            'en' => __('diagnostics::messages.timeline.report', ['number' => $order->number], 'en'),
            'bn' => __('diagnostics::messages.timeline.report', ['number' => $order->number], 'bn'),
        ], CarbonImmutable::now());
        ReportReady::dispatch($company->getKey(), $order->getKey(), $order->patient_id);
    }

    private function lock(Organization $company, Order $order): Order
    {
        return $this->store->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
    }
}
