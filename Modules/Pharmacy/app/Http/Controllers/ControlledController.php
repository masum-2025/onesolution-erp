<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Http\Controllers\Concerns\FindsPharmacy;
use Modules\Pharmacy\Http\PharmacyPresenter;
use Modules\Pharmacy\Models\ControlledEntry;
use Modules\Pharmacy\Services\Medicines;
use Modules\Pharmacy\Services\PharmacyStore;

/**
 * The controlled-drug register at the unit and below (pharmacy.controlled):
 * every in and out, oldest first, for a medicine and dates. It names patients,
 * so each reading is audited.
 */
class ControlledController extends Controller
{
    use FindsPharmacy;

    public function __construct(private PharmacyStore $store, private Medicines $medicines, private PharmacyPresenter $presenter, private AuditLogger $audit) {}

    public function __invoke(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.controlled', $unit);
        $filters = $request->validate(['medicine_id' => ['nullable', 'string', 'size:26'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $entries = $this->store->query(ControlledEntry::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when(isset($filters['medicine_id']), fn ($query) => $query->where('medicine_id', $filters['medicine_id']))
            ->when(isset($filters['from']), fn ($query) => $query->where('recorded_at', '>=', $filters['from'].' 00:00:00'))
            ->when(isset($filters['to']), fn ($query) => $query->where('recorded_at', '<=', $filters['to'].' 23:59:59'))
            ->orderBy('recorded_at')->orderBy('id')->limit(2000)->get();
        $medicines = $this->medicines->details($company, $entries->pluck('medicine_id')->unique()->values()->all())->all();
        $this->audit->record('pharmacy.controlled_viewed', null, new: ['filters' => $filters, 'entries' => $entries->count()], actor: $request->user(), organizationId: $company->getKey());

        return response()->json(['data' => $entries->map(fn (ControlledEntry $entry) => $this->presenter->controlled($entry, $medicines))->values()]);
    }
}
