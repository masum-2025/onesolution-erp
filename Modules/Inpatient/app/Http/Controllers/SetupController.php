<?php

namespace Modules\Inpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Inpatient\Http\Controllers\Concerns\FindsInpatient;
use Modules\Inpatient\Services\InpatientStore;

/**
 * What the module's screens need once (read with inpatient.view): whether the person may write, the company's
 * currency, and the titles of records other records link to (and employees' names) at the unit and below.
 */
class SetupController extends Controller
{
    use FindsInpatient;

    public function __construct(private InpatientStore $store) {}

    public function __invoke(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.view', $unit);
        $units = $this->store->subtreeIds($unit);

        return response()->json(['data' => [
            'can' => collect(['admit', 'nurse', 'round', 'discharge', 'manage'])->mapWithKeys(fn ($action) => [$action => Gate::allows("inpatient.{$action}", $unit)]),
            'currency' => rescue(fn () => $this->store->currency($company), null, false),
            'options' => [
            ],
        ]]);
    }
}
