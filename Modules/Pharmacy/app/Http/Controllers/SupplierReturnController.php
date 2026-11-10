<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Exceptions\PharmacyException;
use Modules\Pharmacy\Http\Controllers\Concerns\FindsPharmacy;
use Modules\Pharmacy\Http\PharmacyPresenter;
use Modules\Pharmacy\Http\Requests\SupplierReturnRequest;
use Modules\Pharmacy\Models\SupplierReturn;
use Modules\Pharmacy\Models\SupplierReturnLine;
use Modules\Pharmacy\Services\Medicines;
use Modules\Pharmacy\Services\PharmacyStore;
use Modules\Pharmacy\Services\SupplierReturns;

/**
 * Supplier returns at the unit and below: listed (pharmacy.view), submitted
 * (pharmacy.manage), approved or rejected by someone else (pharmacy.approve).
 */
class SupplierReturnController extends Controller
{
    use FindsPharmacy;

    public function __construct(private PharmacyStore $store, private SupplierReturns $returns, private Medicines $medicines, private PharmacyPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);
        $filters = $request->validate(['status' => ['nullable', 'in:submitted,approved,rejected'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->store->query(SupplierReturn::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));
        $returns = collect($page->items());
        $lines = $returns->isEmpty() ? collect() : $this->store->query(SupplierReturnLine::class, $company)->whereIn('return_id', $returns->pluck('id')->all())->get()->groupBy('return_id');
        $medicines = $this->medicines->details($company, $lines->flatten()->pluck('medicine_id')->unique()->values()->all())->all();

        return response()->json([
            'data' => $returns->map(fn (SupplierReturn $return) => $this->presenter->supplierReturn($return, $lines[$return->getKey()] ?? [], $medicines, $request->user()?->getKey()))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(SupplierReturnRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.manage', $unit);
        $return = $this->returns->submit($company, $unit, $request->validated(), $request->user());

        return response()->json(['data' => $this->present($company, $return, $request)], $return->wasRecentlyCreated ? 201 : 200);
    }

    public function approve(Request $request, string $organization, string $return): JsonResponse
    {
        return $this->decide($request, $organization, $return, true);
    }

    public function reject(Request $request, string $organization, string $return): JsonResponse
    {
        return $this->decide($request, $organization, $return, false);
    }

    private function decide(Request $request, string $organization, string $id, bool $approve): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->store->query(SupplierReturn::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw PharmacyException::notFound('return');
        Gate::authorize('pharmacy.approve', $unit);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'note' => [$approve ? 'nullable' : 'required', 'string', 'max:300']]);
        $decided = $this->returns->decide($company, $found, (int) $data['base_version'], $approve, $data['note'] ?? null, $request->user());

        return response()->json(['data' => $this->present($company, $decided, $request)]);
    }

    /** @return array<string, mixed> */
    private function present($company, SupplierReturn $return, Request $request): array
    {
        $lines = $this->store->query(SupplierReturnLine::class, $company)->where('return_id', $return->getKey())->get();

        return $this->presenter->supplierReturn($return, $lines, $this->medicines->details($company, $lines->pluck('medicine_id')->unique()->values()->all())->all(), $request->user()?->getKey());
    }
}
