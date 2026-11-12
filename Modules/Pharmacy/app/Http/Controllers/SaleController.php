<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Http\Controllers\Concerns\FindsPharmacy;
use Modules\Pharmacy\Http\PharmacyPresenter;
use Modules\Pharmacy\Http\Requests\SaleRequest;
use Modules\Pharmacy\Models\SaleLink;
use Modules\Pharmacy\Services\PharmacyPrescriptions;
use Modules\Pharmacy\Services\PharmacySales;
use Modules\Pharmacy\Services\PharmacyStock;
use Modules\Pharmacy\Services\PharmacyStore;
use Modules\Pharmacy\Services\PharmacyRules;

/**
 * The pharmacy counter: what a POS counter sells of medicines (packs, MRP,
 * good stock, first expiry), selling (pharmacy.sell and pos.sell at the
 * counter's branch), and the pharmacy's sales (pharmacy.view; the prescription
 * only with pharmacy.controlled).
 */
class SaleController extends Controller
{
    use FindsPharmacy;

    public function __construct(
        private PharmacyStore $store,
        private PharmacySales $sales,
        private PharmacyStock $stock,
        private PharmacyRules $rules,
        private PharmacyPresenter $presenter,
        private PharmacyPrescriptions $prescriptions,
    ) {}

    public function counter(string $organization, string $register): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->counterIn($unit, $company, $register);
        $branch = Organization::query()->findOrFail($found->unit_id);
        abort_unless(Gate::allows('pharmacy.sell', $branch) && Gate::allows('pos.sell', $branch), 403);

        return response()->json(['data' => $this->stock->counter($company, $found), 'meta' => [
            'near_expiry_days' => $this->rules->days('near_expiry_days', $branch),
            'sell_loose_pieces' => $this->rules->flag('sell_loose_pieces', $branch),
            'controlled_requires_prescription' => $this->rules->flag('controlled_requires_prescription', $branch),
            'prescriptions_on' => $this->prescriptions->on($company),
            'prescription_days' => $this->rules->days('prescription_days', $branch),
        ]]);
    }

    /** Doctors' prescriptions to sell from: by the printed code, or the patient's number, mobile or name. */
    public function prescriptions(Request $request, string $organization, string $register): JsonResponse
    {
        [$company, $branch] = $this->sellingAt($organization, $register);
        $term = $request->validate(['search' => ['required', 'string', 'min:2', 'max:60']])['search'];

        return response()->json(['data' => $this->prescriptions->search($company, $branch, $term)]);
    }

    /** One prescription's medicines (opening it is written in the patient's "who looked" log). */
    public function prescription(Request $request, string $organization, string $register, string $consultation): JsonResponse
    {
        [$company, $branch] = $this->sellingAt($organization, $register);

        return response()->json(['data' => $this->prescriptions->show($company, $branch, $consultation, $request->user())]);
    }

    /** @return array{0: Organization, 1: Organization} The company and the counter's branch, where the person may sell. */
    private function sellingAt(string $organization, string $register): array
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->counterIn($unit, $company, $register);
        $branch = Organization::query()->findOrFail($found->unit_id);
        abort_unless(Gate::allows('pharmacy.sell', $branch) && Gate::allows('pos.sell', $branch), 403);

        return [$company, $branch];
    }

    public function store(SaleRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $register = $this->counterIn($unit, $company, $request->validated('register_id'));
        $branch = Organization::query()->findOrFail($register->unit_id);
        abort_unless(Gate::allows('pharmacy.sell', $branch) && Gate::allows('pos.sell', $branch), 403);
        $made = $this->sales->sell($company, $register, $request->validated(), $request->user());

        return response()->json(['data' => [
            ...$this->presenter->sale($made['link'], Gate::allows('pharmacy.controlled', $branch)),
            'change_minor' => (int) $made['sale']->change_minor, 'paid_minor' => (int) $made['sale']->paid_minor,
        ]], $made['link']->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.view', $unit);
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'controlled' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->store->query(SaleLink::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when(isset($filters['from']), fn ($query) => $query->where('sold_at', '>=', $filters['from'].' 00:00:00'))
            ->when(isset($filters['to']), fn ($query) => $query->where('sold_at', '<=', $filters['to'].' 23:59:59'))
            ->when(isset($filters['controlled']), fn ($query) => $query->where('has_controlled', (bool) $filters['controlled']))
            ->orderByDesc('sold_at')->orderByDesc('id')->paginate(PerPage::from($request, 25));
        $prescriptions = Gate::allows('pharmacy.controlled', $unit);

        return response()->json([
            'data' => collect($page->items())->map(fn (SaleLink $link) => $this->presenter->sale($link, $prescriptions))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }
}
