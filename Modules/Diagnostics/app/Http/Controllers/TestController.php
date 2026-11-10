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
use Modules\Diagnostics\Http\Requests\TestRequest;
use Modules\Diagnostics\Models\Test;
use Modules\Diagnostics\Services\DiagnosticsStore;
use Modules\Diagnostics\Services\Tests;

/**
 * Tests of the unit and the units below it: listed (search, filters, pages) and read with
 * diagnostics.view; made, changed and removed with diagnostics.manage.
 */
class TestController extends Controller
{
    use FindsDiagnostics;

    public function __construct(private DiagnosticsStore $store, private Tests $records, private DiagnosticsPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'department' => ['nullable', 'in:haematology,biochemistry,microbiology,immunology,clinical_pathology,imaging,cardiology,other'], 'is_active' => ['nullable', 'boolean'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->store->query(Test::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when($needle !== '', function ($query) use ($needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('code', 'like', $like)->orWhere('service_code', 'like', $like));
            })
            ->when(isset($filters['department']), fn ($query) => $query->where('department', $filters['department']))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Test $record) => $this->presenter->test($record))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $test): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.view', $unit);

        return response()->json(['data' => $this->presenter->test($this->find($unit, $company, $test))]);
    }

    public function store(TestRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.manage', $unit);
        $record = $this->records->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->test($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(TestRequest $request, string $organization, string $test): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $test);
        Gate::authorize('diagnostics.manage', $unit);

        return response()->json(['data' => $this->presenter->test($this->records->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $test): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $test);
        Gate::authorize('diagnostics.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->records->delete($company, $found, $version, $request->user());

        return response()->noContent();
    }

    private function find($unit, $company, string $id): Test
    {
        return $this->store->query(Test::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw DiagnosticsException::notFound('test');
    }
}
