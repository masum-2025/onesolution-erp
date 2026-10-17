<?php

namespace Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Fleet\Http\Controllers\Concerns\FindsFleet;
use Modules\Fleet\Models\Vehicle;
use Modules\Fleet\Services\FleetStore;
use Modules\Fleet\Services\Vehicles;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * What the module's screens need once (read with fleet.view): whether the person may write, the company's
 * currency, and the titles of records other records link to (and employees' names) at the unit and below.
 */
class SetupController extends Controller
{
    use FindsFleet;

    public function __construct(private FleetStore $store) {}

    public function __invoke(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('fleet.view', $unit);
        $units = $this->store->subtreeIds($unit);

        return response()->json(['data' => [
            'can' => ['manage' => Gate::allows('fleet.manage', $unit)],
            'currency' => rescue(fn () => $this->store->currency($company), null, false),
            'options' => [
                'vehicle' => (object) $this->store->query(Vehicle::class, $company)->whereIn('unit_id', $units)->orderBy('created_at')->limit(1000)->get()
                    ->mapWithKeys(fn (Vehicle $record) => [$record->getKey() => Vehicles::title($record)])->all(),
            ],
            'employees' => (object) collect(app(EmployeeDirectory::class)->inUnits($company, $units, null, 2000))->mapWithKeys(fn (EmployeeRecord $employee) => [$employee->id => $employee->name])->all(),
        ]]);
    }
}
