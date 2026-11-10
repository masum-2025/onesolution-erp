<?php

namespace Modules\PatientBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Http\Controllers\Concerns\FindsPatientBilling;
use Modules\PatientBilling\Http\PatientBillingPresenter;
use Modules\PatientBilling\Http\Requests\PanelRequest;
use Modules\PatientBilling\Models\Panel;
use Modules\PatientBilling\Services\Panels;
use Modules\PatientBilling\Services\PatientBillingStore;

/**
 * Corporate and panel clients of the unit and the units below it: listed (search, filters, pages) and read with
 * patient_billing.view; made, changed and removed with patient_billing.manage.
 */
class PanelController extends Controller
{
    use FindsPatientBilling;

    public function __construct(private PatientBillingStore $store, private Panels $records, private PatientBillingPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'kind' => ['nullable', 'in:corporate,panel'], 'is_active' => ['nullable', 'boolean'],
        ]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $page = $this->store->query(Panel::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when($needle !== '', function ($query) use ($needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $like));
            })
            ->when(isset($filters['kind']), fn ($query) => $query->where('kind', $filters['kind']))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Panel $record) => $this->presenter->panel($record))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $panel): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.view', $unit);

        return response()->json(['data' => $this->presenter->panel($this->find($unit, $company, $panel))]);
    }

    public function store(PanelRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.manage', $unit);
        $record = $this->records->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->panel($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(PanelRequest $request, string $organization, string $panel): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $panel);
        Gate::authorize('patient_billing.manage', $unit);

        return response()->json(['data' => $this->presenter->panel($this->records->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $panel): Response
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $panel);
        Gate::authorize('patient_billing.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->records->delete($company, $found, $version, $request->user());

        return response()->noContent();
    }

    private function find($unit, $company, string $id): Panel
    {
        return $this->store->query(Panel::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw PatientBillingException::notFound('panel');
    }
}
