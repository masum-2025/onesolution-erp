<?php

namespace Modules\Diagnostics\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
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
 * An order as the screens, the printed report and the portal read it: the
 * patient, each test with its sample, its parameters with the normal range
 * that fits the patient, the latest values and flags, who entered and who
 * verified them. With verifiedOnly (report, portal) a test shows the values
 * of its latest verified amendment only, and unverified tests show none.
 */
class ReportView
{
    public function __construct(
        private DiagnosticsStore $store,
        private TestSetup $setup,
        private PatientDirectory $patients,
        private PatientCharges $charges,
    ) {}

    /** @return array<string, mixed> */
    public function summary(Organization $company, Order $order): array
    {
        $patient = $this->patients->find($company, $order->patient_id);

        return [
            'id' => $order->getKey(),
            'unit_id' => $order->unit_id,
            'number' => $order->number,
            'status' => $order->status,
            'patient' => $patient === null ? null : ['id' => $patient->id, 'mrn' => $patient->mrn, 'name' => $patient->name, 'sex' => $patient->sex, 'age' => $patient->age],
            'bill_id' => $order->bill_id,
            'due_at' => $order->due_at?->toIso8601String(),
            'late' => $order->due_at !== null && ! in_array($order->status, ['verified', 'delivered'], true) && $order->due_at->isPast(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'version' => $order->version,
        ];
    }

    /** @return array<string, mixed> */
    public function full(Organization $company, Order $order, bool $verifiedOnly = false, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $summary = $this->summary($company, $order);
        $patient = $this->patients->find($company, $order->patient_id);
        $ageDays = $patient?->dateOfBirth === null ? null : (int) CarbonImmutable::parse($patient->dateOfBirth)->diffInDays($this->store->today($company));
        $lines = $this->store->query(OrderTest::class, $company)->where('order_id', $order->getKey())->orderBy('created_at')->orderBy('id')->get();
        $tests = $this->store->query(Test::class, $company)->whereKey($lines->pluck('test_id')->all())->get()->keyBy('id');
        $samples = $this->store->query(Sample::class, $company)->where('order_id', $order->getKey())->orderBy('collected_at')->get();
        $results = $this->store->query(TestResult::class, $company)->whereIn('order_test_id', $lines->modelKeys())->get()->groupBy('order_test_id');
        $checks = $this->store->query(Verification::class, $company)->whereIn('order_test_id', $lines->modelKeys())->orderBy('verified_at')->get()->groupBy('order_test_id');
        $names = $this->names([...$lines->pluck('resulted_by')->all(), ...$checks->flatten()->pluck('verified_by')->all(), ...$samples->pluck('collected_by')->all()]);
        $referrer = $order->referrer_id === null ? null : $this->store->query(Referrer::class, $company)->find($order->referrer_id);

        return [
            ...$summary,
            'patient' => $summary['patient'] === null ? null : [...$summary['patient'], 'date_of_birth' => $patient?->dateOfBirth],
            'referrer' => $referrer === null ? null : ['id' => $referrer->getKey(), 'name' => $referrer->name, 'registration_no' => $referrer->registration_no],
            'notes' => $verifiedOnly ? null : $order->notes,
            'bill_due_minor' => $verifiedOnly || $order->bill_id === null ? null : $this->charges->dueOn($company, $order->bill_id),
            'samples' => $verifiedOnly ? [] : $samples->map(fn (Sample $sample) => [
                'id' => $sample->getKey(), 'barcode' => $sample->barcode, 'sample_type' => $sample->sample_type, 'collected_at' => $sample->collected_at->toIso8601String(),
                'collected_by_name' => $names[$sample->collected_by] ?? null, 'rejected_reason' => $sample->rejected_reason,
            ])->values(),
            'tests' => $lines->map(function (OrderTest $line) use ($company, $tests, $results, $checks, $names, $patient, $ageDays, $verifiedOnly, $locale, $samples) {
                $test = $tests[$line->test_id];
                $verifications = $checks[$line->getKey()] ?? collect();
                $shown = $verifiedOnly ? (int) ($verifications->max('amendment') ?? 0) : $line->amendment;
                $values = ($results[$line->getKey()] ?? collect())->where('amendment', $shown)->keyBy('parameter_id');
                $signed = $verifications->where('amendment', $shown)->last();

                return [
                    'id' => $line->getKey(),
                    'test_id' => $test->getKey(),
                    'code' => $test->code,
                    'name' => $test->textIn('name', $locale),
                    'department' => $test->department,
                    'sample_type' => $test->sample_type,
                    'status' => $line->status,
                    'barcode' => $samples->firstWhere('id', $line->sample_id)?->barcode,
                    'amendment' => $shown,
                    'amended' => $shown > 1,
                    'send_back_note' => $verifiedOnly ? null : $line->send_back_note,
                    'resulted_by_name' => $verifiedOnly ? null : ($names[$line->resulted_by] ?? null),
                    'resulted_by_me' => $line->resulted_by !== null && $line->resulted_by === auth()->id(),
                    'verified_by_name' => $signed === null ? null : ($names[$signed->verified_by] ?? null),
                    'verified_at' => $signed?->verified_at->toIso8601String(),
                    'version' => $line->version,
                    'parameters' => ($verifiedOnly && $shown === 0) ? [] : array_map(function (array $parameter) use ($values, $patient, $ageDays, $locale) {
                        $range = ResultFlags::rangeFor($parameter['ranges'], $patient?->sex, $ageDays);
                        $value = $values[$parameter['id']] ?? null;

                        return [
                            'id' => $parameter['id'],
                            'name' => $parameter['name'][$locale] ?? $parameter['name']['en'] ?? '',
                            'unit' => $parameter['unit'],
                            'result_type' => $parameter['result_type'],
                            'choices' => $parameter['choices'],
                            'decimals' => $parameter['decimals'],
                            'range' => $range === null ? null : array_intersect_key($range, array_flip(['low', 'high', 'critical_low', 'critical_high', 'normal_text'])),
                            'value' => $value?->value,
                            'flag' => $value?->flag,
                        ];
                    }, $this->setup->of($company, $test->getKey(), activeOnly: ! $verifiedOnly || $shown === 0)),
                ];
            })->values(),
        ];
    }

    /**
     * @param  list<string|null>  $ids
     * @return array<string, string>
     */
    private function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : User::query()->whereKey($ids)->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }
}
