<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Pos\Exceptions\PosException;
use Modules\Pos\Http\Controllers\Concerns\FindsPos;
use Modules\Pos\Http\PosPresenter;
use Modules\Pos\Http\Requests\PosRequest;
use Modules\Pos\Models\Payment;
use Modules\Pos\Models\Sale;
use Modules\Pos\Models\SaleLine;
use Modules\Pos\Services\Sales;
use Modules\Pos\Services\Tills;

/**
 * Sales and returns of the counters at the unit in the address and below:
 * read (pos.view or pos.sell), sold (pos.sell at the counter's branch),
 * given back (pos.supervise at the counter's branch).
 */
class SaleController extends Controller
{
    use FindsPos;

    public function __construct(private Tills $tills, private Sales $sales, private PosPresenter $presenter) {}

    /** Sales (?session_id, ?register_id, ?date, ?review=1), newest first. */
    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        abort_unless(Gate::any(['pos.view', 'pos.sell'], $unit), 403);
        $filters = $request->validate(['session_id' => ['nullable', 'string', 'max:26'], 'register_id' => ['nullable', 'string', 'max:26'], 'date' => ['nullable', 'date_format:Y-m-d'], 'review' => ['nullable', 'boolean'], 'number' => ['nullable', 'string', 'max:40']]);
        $query = $this->tills->query(Sale::class, $company)->whereIn('register_id', $this->registerIds($unit, $company))->orderByDesc('sold_at')->limit(300);
        foreach (['session_id', 'register_id', 'number'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['date'])) {
            $query->where('sold_at', '>=', "{$filters['date']} 00:00:00")->where('sold_at', '<=', "{$filters['date']} 23:59:59");
        }
        if (! empty($filters['review'])) {
            $query->whereNotNull('review_reason');
        }

        return response()->json(['data' => $query->get()->map(fn (Sale $sale) => $this->presenter->sale($sale))->values()]);
    }

    public function store(PosRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $register = $this->registerIn($unit, $company, $request->validated('register_id'));
        Gate::authorize('pos.sell', $this->unitOf($register->unit_id));

        return response()->json(['data' => $this->full($company, $this->sales->sell($company, $register, $request->safe()->except('register_id'), $request->user()))], 201);
    }

    public function show(string $organization, string $sale): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        abort_unless(Gate::any(['pos.view', 'pos.sell'], $unit), 403);

        return response()->json(['data' => $this->full($company, $this->saleIn($unit, $company, $sale))]);
    }

    public function giveBack(PosRequest $request, string $organization, string $sale): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->saleIn($unit, $company, $sale);
        $register = $this->registerIn($unit, $company, $request->validated('register_id'));
        Gate::authorize('pos.supervise', $this->unitOf($register->unit_id));

        return response()->json(['data' => $this->full($company, $this->sales->giveBack($company, $found, $register, $request->safe()->except('register_id'), $request->user()))], 201);
    }

    private function saleIn(Organization $unit, Organization $company, string $id): Sale
    {
        $sale = $this->tills->query(Sale::class, $company)->whereKey($id)->first();
        if ($sale === null || ! in_array($sale->register_id, $this->registerIds($unit, $company), true)) {
            throw PosException::notFound('sale');
        }

        return $sale;
    }

    /**
     * @return array<string, mixed>
     */
    private function full(Organization $company, Sale $sale): array
    {
        return $this->presenter->sale($sale,
            $this->tills->query(SaleLine::class, $company)->where('sale_id', $sale->getKey())->orderBy('line_no')->get(),
            $this->tills->query(Payment::class, $company)->where('sale_id', $sale->getKey())->get(),
            ['company' => $company->displayName(), 'returns' => $this->tills->query(Sale::class, $company)->where('original_sale_id', $sale->getKey())->pluck('number')->all()]);
    }
}
