<?php

namespace Modules\Inpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Inpatient\Exceptions\InpatientException;
use Modules\Inpatient\Http\Controllers\Concerns\FindsInpatient;
use Modules\Inpatient\Http\InpatientPresenter;
use Modules\Inpatient\Http\Requests\WardRequest;
use Modules\Inpatient\Models\Ward;
use Modules\Inpatient\Services\InpatientStore;
use Modules\Inpatient\Services\Wards;

/**
 * Wards and cabins of the unit and the units below it: listed (search, filters, pages) and read with
 * inpatient.view; made, changed and removed with inpatient.manage.
 */
class WardController extends Controller
{
    use FindsInpatient;

    public function __construct(private InpatientStore $store, private Wards $records, private InpatientPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'kind' => ['nullable', 'in:general,cabin,icu,hdu,nicu,other'], 'is_active' => ['nullable', 'boolean'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->store->query(Ward::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when(isset($filters['kind']), fn ($query) => $query->where('kind', $filters['kind']))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Ward $record) => $this->presenter->ward($record))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $ward): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.view', $unit);

        return response()->json(['data' => $this->presenter->ward($this->find($unit, $company, $ward))]);
    }

    public function store(WardRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inpatient.manage', $unit);
        $record = $this->records->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->ward($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(WardRequest $request, string $organization, string $ward): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $ward);
        Gate::authorize('inpatient.manage', $unit);

        return response()->json(['data' => $this->presenter->ward($this->records->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $ward): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $ward);
        Gate::authorize('inpatient.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->records->delete($company, $found, $version, $request->user());

        return response()->noContent();
    }

    private function find($unit, $company, string $id): Ward
    {
        return $this->store->query(Ward::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw InpatientException::notFound('ward');
    }
}
