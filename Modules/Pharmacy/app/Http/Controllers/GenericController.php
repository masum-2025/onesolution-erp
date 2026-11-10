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
use Modules\Pharmacy\Http\Requests\GenericRequest;
use Modules\Pharmacy\Models\Generic;
use Modules\Pharmacy\Services\Generics;
use Modules\Pharmacy\Services\PharmacyStore;

/**
 * Generics of the unit and the units below it: listed (search, filters, pages) and read with
 * pharmacy.view; made, changed and removed with pharmacy.manage.
 */
class GenericController extends Controller
{
    use FindsPharmacy;

    public function __construct(private PharmacyStore $store, private Generics $records, private PharmacyPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'is_controlled' => ['nullable', 'boolean'], 'in_use' => ['nullable', 'boolean'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->store->query(Generic::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when($needle !== '', function ($query) use ($needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('drug_class', 'like', $like));
            })
            ->when(isset($filters['is_controlled']), fn ($query) => $query->where('is_controlled', (bool) $filters['is_controlled']))
            ->when(isset($filters['in_use']), fn ($query) => $query->where('in_use', (bool) $filters['in_use']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Generic $record) => $this->presenter->generic($record))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $generic): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);

        return response()->json(['data' => $this->presenter->generic($this->find($unit, $company, $generic))]);
    }

    public function store(GenericRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.manage', $unit);
        $record = $this->records->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->generic($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(GenericRequest $request, string $organization, string $generic): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $generic);
        Gate::authorize('pharmacy.manage', $unit);

        return response()->json(['data' => $this->presenter->generic($this->records->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $generic): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $generic);
        Gate::authorize('pharmacy.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->records->delete($company, $found, $version, $request->user());

        return response()->noContent();
    }

    private function find($unit, $company, string $id): Generic
    {
        return $this->store->query(Generic::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw PharmacyException::notFound('generic');
    }
}
