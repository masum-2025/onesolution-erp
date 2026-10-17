<?php

namespace Modules\FactoryErp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Crm\Services\Organizations;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Http\Controllers\Concerns\FindsFactory;
use Modules\FactoryErp\Http\FactoryPresenter;
use Modules\FactoryErp\Http\Requests\ShipmentRequest;
use Modules\FactoryErp\Models\LetterOfCredit;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Shipment;
use Modules\FactoryErp\Models\Style;
use Modules\FactoryErp\Services\Floor;
use Modules\FactoryErp\Services\LettersOfCredit;
use Modules\FactoryErp\Services\Progress;
use Modules\FactoryErp\Services\Shipments;
use Modules\Inventory\Services\Stock;

/**
 * Shipments of orders at the unit: listed per order and read (with what
 * the packing list and commercial invoice print) with factory_erp.view;
 * made and changed while draft with factory_erp.merchandise; shipped
 * (goods leave the store) with factory_erp.issue.
 */
class ShipmentController extends Controller
{
    use FindsFactory;

    public function __construct(
        private Floor $floor,
        private Shipments $shipments,
        private Progress $progress,
        private Organizations $buyers,
        private Stock $stock,
        private FactoryPresenter $presenter,
        private LettersOfCredit $lcs,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function index(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $found = $this->orderIn($unit, $company, $order);
        $progress = $this->progress->of($company, $found);
        $shipped = $this->shipments->shipped($company, $found);

        return response()->json([
            'data' => $this->shipments->of($company, $found)->map(fn (Shipment $shipment) => $this->presenter->shipment($shipment))->values(),
            'meta' => ['ready' => (object) collect($progress)->map(fn (array $cell, string $key) => max(0, $cell['received'] - ($shipped[$key] ?? 0)))->filter()->all()],
        ]);
    }

    public function show(string $organization, string $shipment): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);

        return $this->answer($company, $this->shipmentIn($unit, $company, $shipment));
    }

    public function store(ShipmentRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.merchandise', $unit);
        $made = $this->shipments->save($company, $unit, null, $request->validated(), $request->user());

        return $this->answer($company, $made, $made->wasRecentlyCreated ? 201 : 200);
    }

    public function update(ShipmentRequest $request, string $organization, string $shipment): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->shipmentIn($unit, $company, $shipment);
        Gate::authorize('factory_erp.merchandise', $unit);

        return $this->answer($company, $this->shipments->save($company, $unit, $found, $request->validated(), $request->user()));
    }

    public function ship(Request $request, string $organization, string $shipment): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->shipmentIn($unit, $company, $shipment);
        Gate::authorize('factory_erp.issue', $unit);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'accept_discrepancy' => ['sometimes', 'boolean']]);

        return $this->answer($company, $this->shipments->ship($company, $found, (int) $data['base_version'], $request->user(), (bool) ($data['accept_discrepancy'] ?? false)));
    }

    private function shipmentIn($unit, $company, string $id): Shipment
    {
        return $this->floor->query(Shipment::class, $company)->whereKey($id)->whereIn('unit_id', $this->floor->subtreeIds($unit))->first()
            ?? throw FactoryException::notFound('shipment');
    }

    /** The shipment with its packing list, and the order, style, buyer, store and seller the printed papers need. */
    private function answer($company, Shipment $shipment, int $status = 200): JsonResponse
    {
        $order = $this->floor->query(Order::class, $company)->whereKey($shipment->order_id)->firstOrFail();
        $style = $this->floor->query(Style::class, $company)->whereKey($order->style_id)->firstOrFail();

        return response()->json([
            'data' => $this->presenter->shipment($shipment, $this->shipments->cartons($company, $shipment)),
            'meta' => [
                'order' => $this->presenter->order($order),
                'style' => ['code' => $style->code, 'name' => $style->name, 'sizes' => $style->sizes, 'colors' => $style->colors, 'product_type' => $style->product_type],
                'buyer' => $this->buyers->many($company, [$order->buyer_id])[$order->buyer_id] ?? null,
                'warehouse' => $this->stock->warehouse($company, $shipment->warehouse_id),
                'seller' => ['name' => $company->displayName()],
                'lc' => $shipment->lc_id === null ? null : $this->presenter->lc($this->floor->query(LetterOfCredit::class, $company)->findOrFail($shipment->lc_id)),
                'lcs' => $this->lcs->of($company, $company, 'open')->where('buyer_id', $order->buyer_id)->where('currency', $order->currency)
                    ->map(fn (LetterOfCredit $lc) => ['id' => $lc->getKey(), 'number' => $lc->number])->values(),
                'export_permit' => [
                    'required' => (bool) $this->rules->get('factory_erp.export_permit_required', $this->contexts->forOrganization($company)),
                    'label' => (string) $this->rules->get('factory_erp.export_permit_label', $this->contexts->forOrganization($company)),
                ],
                'company_currency' => rescue(fn () => $this->floor->currency($company), null, false),
            ],
        ], $status);
    }
}
