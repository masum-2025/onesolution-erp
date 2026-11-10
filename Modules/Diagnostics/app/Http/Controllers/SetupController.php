<?php

namespace Modules\Diagnostics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Diagnostics\Http\Controllers\Concerns\FindsDiagnostics;
use Modules\Diagnostics\Models\Referrer;
use Modules\Diagnostics\Models\Test;
use Modules\Diagnostics\Services\DiagnosticsStore;
use Modules\PatientBilling\Contracts\PatientCharges;

/**
 * What the lab screens need once (diagnostics.view): what the person may do,
 * the active tests with their price (from the billing service), the
 * referring doctors, and whether results need verifying here.
 */
class SetupController extends Controller
{
    use FindsDiagnostics;

    public function __construct(private DiagnosticsStore $store, private PatientCharges $charges) {}

    public function __invoke(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.view', $unit);
        $services = $this->charges->services($company);

        return response()->json(['data' => [
            'can' => collect(['order', 'collect', 'result', 'verify', 'deliver', 'manage'])->mapWithKeys(fn (string $ability) => [$ability => Gate::allows("diagnostics.{$ability}", $unit)])->all(),
            'currency' => rescue(fn () => $this->store->currency($company), null, false),
            'require_verification' => (bool) $this->store->rule('require_verification', $unit),
            'options' => [
                'tests' => $this->store->query(Test::class, $company)->where('is_active', true)->orderBy('code')->get()->map(fn (Test $test) => [
                    'id' => $test->getKey(), 'code' => $test->code, 'name' => $test->textIn('name'), 'department' => $test->department, 'sample_type' => $test->sample_type,
                    'price_minor' => $services[$test->service_code]['price_minor'] ?? null,
                ])->values(),
                'referrers' => $this->store->query(Referrer::class, $company)->where('is_active', true)->orderBy('name')->get()
                    ->map(fn (Referrer $referrer) => ['id' => $referrer->getKey(), 'name' => $referrer->name, 'registration_no' => $referrer->registration_no])->values(),
                'services' => collect($services)->map(fn (array $service, string $code) => ['code' => $code, 'name' => $service['name']])->values(),
            ],
        ]]);
    }
}
