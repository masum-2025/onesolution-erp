<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Exceptions\PharmacyException;
use Modules\Pharmacy\Http\Controllers\Concerns\FindsPharmacy;
use Modules\Pharmacy\Http\PharmacyPresenter;
use Modules\Pharmacy\Http\Requests\ManufacturerRequest;
use Modules\Pharmacy\Models\Manufacturer;
use Modules\Pharmacy\Services\Manufacturers;
use Modules\Pharmacy\Services\PharmacyStore;

/**
 * Manufacturers of the unit and the units below it: listed (search, filters, pages) and read with
 * pharmacy.view; made, changed and removed with pharmacy.manage.
 */
class ManufacturerController extends Controller
{
    use FindsPharmacy;

    public function __construct(private PharmacyStore $store, private Manufacturers $records, private PharmacyPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'in_use' => ['nullable', 'boolean'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->store->query(Manufacturer::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when($needle !== '', function ($query) use ($needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('country', 'like', $like));
            })
            ->when(isset($filters['in_use']), fn ($query) => $query->where('in_use', (bool) $filters['in_use']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Manufacturer $record) => $this->presenter->manufacturer($record))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $manufacturer): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);

        return response()->json(['data' => $this->presenter->manufacturer($this->find($unit, $company, $manufacturer))]);
    }

    public function store(ManufacturerRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.manage', $unit);
        $record = $this->records->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->manufacturer($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(ManufacturerRequest $request, string $organization, string $manufacturer): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $manufacturer);
        Gate::authorize('pharmacy.manage', $unit);

        return response()->json(['data' => $this->presenter->manufacturer($this->records->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $manufacturer): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $manufacturer);
        Gate::authorize('pharmacy.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->records->delete($company, $found, $version, $request->user());

        return response()->noContent();
    }

    private function find($unit, $company, string $id): Manufacturer
    {
        return $this->store->query(Manufacturer::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw PharmacyException::notFound('manufacturer');
    }
}
