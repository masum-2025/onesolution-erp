<?php

namespace Modules\FactoryErp\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Crm\Services\Organizations;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Http\Controllers\Concerns\FindsFactory;
use Modules\FactoryErp\Http\FactoryPresenter;
use Modules\FactoryErp\Http\Requests\LetterOfCreditRequest;
use Modules\FactoryErp\Models\LetterOfCredit;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Shipment;
use Modules\FactoryErp\Services\Floor;
use Modules\FactoryErp\Services\LettersOfCredit;

/**
 * Letters of credit at the unit: listed with what each has paid for,
 * read with its orders and shipments (factory_erp.view), made and changed
 * with factory_erp.merchandise.
 */
class LcController extends Controller
{
    use FindsFactory;

    public function __construct(private Floor $floor, private LettersOfCredit $lcs, private Organizations $buyers, private FactoryPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $status = $request->validate(['status' => ['nullable', 'in:open,closed']])['status'] ?? null;
        $lcs = $this->lcs->of($company, $unit, $status);

        return response()->json([
            'data' => $lcs->map(fn (LetterOfCredit $lc) => $this->presenter->lc($lc, $this->lcs->usage($company, $lc)))->values(),
            'meta' => ['buyers' => (object) $this->buyers->many($company, $lcs->pluck('buyer_id')->unique()->values()->all())],
        ]);
    }

    public function show(string $organization, string $lc): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);

        return $this->answer($company, $this->lcIn($unit, $company, $lc));
    }

    public function store(LetterOfCreditRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.merchandise', $unit);

        return $this->answer($company, $this->lcs->save($company, $unit, null, $request->validated(), $request->user()), 201);
    }

    public function update(LetterOfCreditRequest $request, string $organization, string $lc): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->lcIn($unit, $company, $lc);
        Gate::authorize('factory_erp.merchandise', $unit);

        return $this->answer($company, $this->lcs->save($company, $unit, $found, $request->validated(), $request->user()));
    }

    private function lcIn($unit, $company, string $id): LetterOfCredit
    {
        return $this->floor->query(LetterOfCredit::class, $company)->whereKey($id)->whereIn('unit_id', $this->floor->subtreeIds($unit))->first()
            ?? throw FactoryException::notFound('lc');
    }

    /** The L/C with what it has paid for, its orders (and the buyer's others it could take), and its shipments. */
    private function answer($company, LetterOfCredit $lc, int $status = 200): JsonResponse
    {
        $orderIds = $this->lcs->orderIds($company, $lc);
        $candidates = $this->floor->query(Order::class, $company)->where('buyer_id', $lc->buyer_id)->where('currency', $lc->currency)
            ->whereNotIn('status', ['cancelled'])->orderBy('ship_on')->get();
        $shipments = $this->floor->query(Shipment::class, $company)->where('lc_id', $lc->getKey())->orderBy('ship_on')->get();

        return response()->json([
            'data' => [...$this->presenter->lc($lc, $this->lcs->usage($company, $lc)), 'order_ids' => $orderIds],
            'meta' => [
                'buyer' => $this->buyers->many($company, [$lc->buyer_id])[$lc->buyer_id] ?? null,
                'orders' => $candidates->map(fn (Order $order) => ['id' => $order->getKey(), 'number' => $order->number, 'po_number' => $order->po_number, 'status' => $order->status,
                    'ship_on' => $order->ship_on->toDateString(), 'on_lc' => in_array($order->getKey(), $orderIds, true)])->values(),
                'shipments' => $shipments->map(fn (Shipment $shipment) => $this->presenter->shipment($shipment))->values(),
                'documents' => LetterOfCredit::DOCUMENTS,
            ],
        ], $status);
    }
}
