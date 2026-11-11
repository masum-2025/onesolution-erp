<?php

namespace Modules\Outpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Http\Controllers\Concerns\FindsOutpatient;
use Modules\Outpatient\Http\OutpatientPresenter;
use Modules\Outpatient\Http\Requests\ChamberRequest;
use Modules\Outpatient\Models\Chamber;
use Modules\Outpatient\Services\Chambers;
use Modules\Outpatient\Services\OutpatientStore;

/**
 * Chambers of the unit and the units below it: listed (search, filters, pages) and read with
 * outpatient.view; made, changed and removed with outpatient.manage.
 */
class ChamberController extends Controller
{
    use FindsOutpatient;

    public function __construct(private OutpatientStore $store, private Chambers $records, private OutpatientPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'is_active' => ['nullable', 'boolean'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->store->query(Chamber::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when($needle !== '', function ($query) use ($needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('room', 'like', $like));
            })
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Chamber $record) => $this->presenter->chamber($record))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $chamber): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.view', $unit);

        return response()->json(['data' => $this->presenter->chamber($this->find($unit, $company, $chamber))]);
    }

    public function store(ChamberRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.manage', $unit);
        $record = $this->records->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->chamber($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(ChamberRequest $request, string $organization, string $chamber): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $chamber);
        Gate::authorize('outpatient.manage', $unit);

        return response()->json(['data' => $this->presenter->chamber($this->records->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $chamber): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $chamber);
        Gate::authorize('outpatient.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->records->delete($company, $found, $version, $request->user());

        return response()->noContent();
    }

    private function find($unit, $company, string $id): Chamber
    {
        return $this->store->query(Chamber::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw OutpatientException::notFound('chamber');
    }
}
