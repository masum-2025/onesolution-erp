<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Http\Controllers\Concerns\FindsPharmacy;
use Modules\Pharmacy\Http\PharmacyPresenter;
use Modules\Pharmacy\Http\Requests\MedicineRequest;
use Modules\Pharmacy\Models\Medicine;
use Modules\Pharmacy\Services\Medicines;

/**
 * The company's medicines: listed and searched by brand, generic, SKU or
 * barcode, read, and their substitutes, with pharmacy.view; made and changed
 * with pharmacy.manage.
 */
class MedicineController extends Controller
{
    use FindsPharmacy;

    public function __construct(private Medicines $medicines, private PharmacyPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'generic_id' => ['nullable', 'string', 'size:26'], 'form' => ['nullable', 'in:'.implode(',', Medicine::FORMS)],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $rows = $this->medicines->search($company, (string) ($filters['q'] ?? ''), $filters['generic_id'] ?? null, $filters['form'] ?? null)
            ->sortBy(fn (array $row) => mb_strtolower((string) ($row['item']['name'] ?? '')))->values();
        $per = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);

        return response()->json([
            'data' => $rows->forPage($page, $per)->map(fn (array $row) => $this->presenter->medicine($row))->values(),
            'meta' => ['total' => $rows->count(), 'page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / $per))],
        ]);
    }

    public function show(string $organization, string $medicine): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);
        $found = $this->medicineIn($unit, $company, $medicine);

        return response()->json(['data' => $this->presenter->medicine($this->medicines->details($company, [$found->getKey()])[$found->getKey()])]);
    }

    public function substitutes(string $organization, string $medicine): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);
        $found = $this->medicineIn($unit, $company, $medicine);

        return response()->json(['data' => $this->medicines->substitutes($company, $found)->map(fn (array $row) => $this->presenter->medicine($row))->values()]);
    }

    public function store(MedicineRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.manage', $unit);
        $record = $this->medicines->save($company, $unit, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->medicine($this->medicines->details($company, [$record->getKey()])[$record->getKey()])], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(MedicineRequest $request, string $organization, string $medicine): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->medicineIn($unit, $company, $medicine);
        Gate::authorize('pharmacy.manage', $unit);
        $record = $this->medicines->save($company, $unit, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->medicine($this->medicines->details($company, [$record->getKey()])[$record->getKey()])]);
    }
}
