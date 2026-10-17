<?php

namespace Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Fleet\Exceptions\FleetException;
use Modules\Fleet\Http\Controllers\Concerns\FindsFleet;
use Modules\Fleet\Http\FleetPresenter;
use Modules\Fleet\Http\Requests\VehicleRequest;
use Modules\Fleet\Models\Vehicle;
use Modules\Fleet\Services\FleetStore;
use Modules\Fleet\Services\Vehicles;

/**
 * Vehicles of the unit and the units below it: listed (search, filters, pages) and read with
 * fleet.view; made, changed and removed with fleet.manage.
 */
class VehicleController extends Controller
{
    use FindsFleet;

    public function __construct(private FleetStore $store, private Vehicles $records, private FleetPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('fleet.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'kind' => ['nullable', 'in:truck,van,car,motorcycle'], 'in_use' => ['nullable', 'boolean'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->store->query(Vehicle::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when($needle !== '', function ($query) use ($needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('registration_no', 'like', $like)->orWhere('model', 'like', $like));
            })
            ->when(isset($filters['kind']), fn ($query) => $query->where('kind', $filters['kind']))
            ->when(isset($filters['in_use']), fn ($query) => $query->where('in_use', (bool) $filters['in_use']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Vehicle $record) => $this->presenter->vehicle($record))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $vehicle): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('fleet.view', $unit);

        return response()->json(['data' => $this->presenter->vehicle($this->find($unit, $company, $vehicle))]);
    }

    public function store(VehicleRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('fleet.manage', $unit);
        $record = $this->records->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->vehicle($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(VehicleRequest $request, string $organization, string $vehicle): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $vehicle);
        Gate::authorize('fleet.manage', $unit);

        return response()->json(['data' => $this->presenter->vehicle($this->records->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $vehicle): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $vehicle);
        Gate::authorize('fleet.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->records->delete($company, $found, $version, $request->user());

        return response()->noContent();
    }

    private function find($unit, $company, string $id): Vehicle
    {
        return $this->store->query(Vehicle::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw FleetException::notFound('vehicle');
    }
}
