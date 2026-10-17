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
use Modules\Fleet\Http\Requests\TripRequest;
use Modules\Fleet\Models\Trip;
use Modules\Fleet\Services\FleetStore;
use Modules\Fleet\Services\Trips;

/**
 * Trips of the unit and the units below it: listed (search, filters, pages) and read with
 * fleet.view; made, changed and removed with fleet.manage.
 */
class TripController extends Controller
{
    use FindsFleet;

    public function __construct(private FleetStore $store, private Trips $records, private FleetPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('fleet.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'vehicle_id' => ['nullable', 'string', 'size:26'], 'driver_id' => ['nullable', 'string', 'size:26'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->store->query(Trip::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when($needle !== '', function ($query) use ($needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('purpose', 'like', $like)->orWhere('destination', 'like', $like));
            })
            ->when(isset($filters['vehicle_id']), fn ($query) => $query->where('vehicle_id', $filters['vehicle_id']))
            ->when(isset($filters['driver_id']), fn ($query) => $query->where('driver_id', $filters['driver_id']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Trip $record) => $this->presenter->trip($record))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $trip): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('fleet.view', $unit);

        return response()->json(['data' => $this->presenter->trip($this->find($unit, $company, $trip))]);
    }

    public function store(TripRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('fleet.manage', $unit);
        $record = $this->records->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->trip($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(TripRequest $request, string $organization, string $trip): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $trip);
        Gate::authorize('fleet.manage', $unit);

        return response()->json(['data' => $this->presenter->trip($this->records->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $trip): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $trip);
        Gate::authorize('fleet.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->records->delete($company, $found, $version, $request->user());

        return response()->noContent();
    }

    private function find($unit, $company, string $id): Trip
    {
        return $this->store->query(Trip::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw FleetException::notFound('trip');
    }
}
