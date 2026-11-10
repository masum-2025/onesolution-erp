<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Http\Controllers\Concerns\FindsPharmacy;
use Modules\Pharmacy\Services\PharmacyStock;

/**
 * Medicine batches in the unit's warehouses with their expiry (pharmacy.view):
 * all, expiring soon, due back to the supplier, or expired.
 */
class StockController extends Controller
{
    use FindsPharmacy;

    public function __construct(private PharmacyStock $stock) {}

    public function __invoke(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);
        $filters = $request->validate(['show' => ['nullable', 'in:all,near,return,expired'], 'warehouse_id' => ['nullable', 'string', 'size:26']]);

        return response()->json(['data' => $this->stock->batches($company, $unit, $filters['show'] ?? 'all', $filters['warehouse_id'] ?? null)]);
    }
}
