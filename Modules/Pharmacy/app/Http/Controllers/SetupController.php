<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Services\Stock;
use Modules\Pharmacy\Http\Controllers\Concerns\FindsPharmacy;
use Modules\Pharmacy\Models\Generic;
use Modules\Pharmacy\Models\Manufacturer;
use Modules\Pharmacy\Models\Medicine;
use Modules\Pharmacy\Services\PharmacyStore;

/**
 * What the module's screens need once (read with pharmacy.view): what the person may do, the company's
 * currency, the medicine forms, generics, manufacturers and the unit's warehouses.
 */
class SetupController extends Controller
{
    use FindsPharmacy;

    public function __construct(private PharmacyStore $store, private Stock $stock) {}

    public function __invoke(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);
        $units = $this->store->subtreeIds($unit);

        return response()->json(['data' => [
            'can' => [
                'manage' => Gate::allows('pharmacy.manage', $unit), 'sell' => Gate::allows('pharmacy.sell', $unit), 'controlled' => Gate::allows('pharmacy.controlled', $unit),
                'approve' => Gate::allows('pharmacy.approve', $unit), 'buy' => Gate::allows('pharmacy.manage', $unit) && Gate::allows('inventory.manage', $unit),
            ],
            'currency' => rescue(fn () => $this->store->currency($company), null, false),
            'forms' => Medicine::FORMS,
            'options' => [
                'generic_id' => $this->store->query(Generic::class, $company)->where('in_use', true)->get()->map(fn (Generic $generic) => ['id' => $generic->getKey(), 'name' => (object) $generic->texts('name'), 'is_controlled' => $generic->is_controlled])->values(),
                'manufacturer_id' => $this->store->query(Manufacturer::class, $company)->where('in_use', true)->get()->map(fn (Manufacturer $maker) => ['id' => $maker->getKey(), 'name' => (object) $maker->texts('name')])->values(),
                'warehouse_id' => collect($this->stock->warehouses($company))->whereIn('unit_id', $units)->values(),
            ],
        ]]);
    }
}
