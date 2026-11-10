<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Http\Controllers\Concerns\FindsPharmacy;
use Modules\Pharmacy\Http\PharmacyPresenter;
use Modules\Pharmacy\Http\Requests\PurchaseRequest;
use Modules\Pharmacy\Models\Purchase;
use Modules\Pharmacy\Services\PharmacyStore;
use Modules\Pharmacy\Services\Purchases;

/**
 * Medicine purchases at the unit and below: listed (pharmacy.view) and made (pharmacy.manage and inventory.manage).
 */
class PurchaseController extends Controller
{
    use FindsPharmacy;

    public function __construct(private PharmacyStore $store, private Purchases $purchases, private PharmacyPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);
        $page = $this->store->query(Purchase::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->orderByDesc('purchased_on')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Purchase $purchase) => $this->presenter->purchase($purchase))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(PurchaseRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        abort_unless(Gate::allows('pharmacy.manage', $unit) && Gate::allows('inventory.manage', $unit), 403);
        $purchase = $this->purchases->record($company, $unit, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->purchase($purchase)], $purchase->wasRecentlyCreated ? 201 : 200);
    }
}
