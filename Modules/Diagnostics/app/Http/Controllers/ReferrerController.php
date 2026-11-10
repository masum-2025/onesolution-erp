<?php

namespace Modules\Diagnostics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Http\Controllers\Concerns\FindsDiagnostics;
use Modules\Diagnostics\Http\DiagnosticsPresenter;
use Modules\Diagnostics\Http\Requests\ReferrerRequest;
use Modules\Diagnostics\Models\Referrer;
use Modules\Diagnostics\Services\DiagnosticsStore;
use Modules\Diagnostics\Services\Referrers;

/**
 * Referring doctors of the unit and the units below it: listed (search, filters, pages) and read with
 * diagnostics.view; made, changed and removed with diagnostics.manage.
 */
class ReferrerController extends Controller
{
    use FindsDiagnostics;

    public function __construct(private DiagnosticsStore $store, private Referrers $records, private DiagnosticsPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'is_active' => ['nullable', 'boolean'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->store->query(Referrer::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when($needle !== '', function ($query) use ($needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('registration_no', 'like', $like)->orWhere('mobile', 'like', $like));
            })
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Referrer $record) => $this->presenter->referrer($record))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $referrer): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.view', $unit);

        return response()->json(['data' => $this->presenter->referrer($this->find($unit, $company, $referrer))]);
    }

    public function store(ReferrerRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.manage', $unit);
        $record = $this->records->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->referrer($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(ReferrerRequest $request, string $organization, string $referrer): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $referrer);
        Gate::authorize('diagnostics.manage', $unit);

        return response()->json(['data' => $this->presenter->referrer($this->records->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $referrer): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $referrer);
        Gate::authorize('diagnostics.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->records->delete($company, $found, $version, $request->user());

        return response()->noContent();
    }

    private function find($unit, $company, string $id): Referrer
    {
        return $this->store->query(Referrer::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw DiagnosticsException::notFound('referrer');
    }
}
