<?php

namespace Modules\Outpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Outpatient\Http\Controllers\Concerns\FindsOutpatient;
use Modules\Outpatient\Services\OutpatientStore;
use Modules\PatientBilling\Contracts\PatientCharges;

/**
 * What the module's screens need once (read with outpatient.view): whether the person may write, the company's
 * currency, and the titles of records other records link to (and employees' names) at the unit and below.
 */
class SetupController extends Controller
{
    use FindsOutpatient;

    public function __construct(private OutpatientStore $store) {}

    public function __invoke(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.view', $unit);
        $units = $this->store->subtreeIds($unit);

        return response()->json(['data' => [
            'can' => ['manage' => Gate::allows('outpatient.manage', $unit), 'book' => Gate::allows('outpatient.book', $unit), 'consult' => Gate::allows('outpatient.consult', $unit)],
            'services' => Gate::allows('outpatient.manage', $unit) ? app(PatientCharges::class)->services($company) : (object) [],
            'currency' => rescue(fn () => $this->store->currency($company), null, false),
            'options' => [
            ],
        ]]);
    }
}
