<?php

namespace Modules\Diagnostics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Http\Controllers\Concerns\FindsDiagnostics;
use Modules\Diagnostics\Http\Requests\ParametersRequest;
use Modules\Diagnostics\Models\Test;
use Modules\Diagnostics\Services\DiagnosticsStore;
use Modules\Diagnostics\Services\TestSetup;

/**
 * A test's parameters and normal ranges: read with diagnostics.view, saved
 * with diagnostics.manage. Tests are the company's (one catalogue for every
 * branch).
 */
class TestSetupController extends Controller
{
    use FindsDiagnostics;

    public function __construct(private DiagnosticsStore $store, private TestSetup $setup) {}

    public function show(string $organization, string $test): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.view', $unit);
        $found = $this->find($company, $test);

        return response()->json(['data' => ['test' => ['id' => $found->getKey(), 'code' => $found->code, 'name' => $found->textIn('name'), 'sample_type' => $found->sample_type],
            'parameters' => $this->setup->of($company, $found->getKey())]]);
    }

    public function update(ParametersRequest $request, string $organization, string $test): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($company, $test);
        Gate::authorize('diagnostics.manage', $unit);
        $this->setup->save($company, $found, $request->validated('parameters'), $request->user());

        return response()->json(['data' => ['test' => ['id' => $found->getKey(), 'code' => $found->code, 'name' => $found->textIn('name'), 'sample_type' => $found->sample_type],
            'parameters' => $this->setup->of($company, $found->getKey())]]);
    }

    private function find($company, string $id): Test
    {
        return $this->store->query(Test::class, $company)->whereKey($id)->first() ?? throw DiagnosticsException::notFound('test');
    }
}
