<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Http\Controllers\Concerns\FindsInventory;
use Modules\Inventory\Http\InventoryPresenter;
use Modules\Inventory\Http\Requests\CountRequest;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\Counts;
use Modules\Inventory\Services\Inventories;

/**
 * Stock counts of the warehouses at the unit in the address and below:
 * read (inventory.view), opened, recorded, sent and cancelled
 * (inventory.manage at the warehouse's unit), approved or rejected
 * (inventory.approve; never one's own).
 */
class CountController extends Controller
{
    use FindsInventory;

    public function __construct(private Inventories $inventories, private Counts $counts, private InventoryPresenter $presenter) {}

    public function index(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);

        return response()->json(['data' => $this->inventories->query(StockCount::class, $company)->whereIn('warehouse_id', $this->warehouseIds($unit, $company))
            ->orderByDesc('counted_on')->orderByDesc('created_at')->limit(200)->get()->map(fn (StockCount $count) => $this->presenter->count($count))->values()]);
    }

    public function store(CountRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $warehouse = $this->warehouseIn($unit, $company, $request->validated('warehouse_id'));
        Gate::authorize('inventory.manage', $this->unitOf($warehouse->unit_id));
        $count = $this->counts->open($company, $warehouse, $request->validated('category_id'), CarbonImmutable::parse($request->validated('counted_on'), 'UTC'), $request->user());

        return response()->json(['data' => $this->full($company, $count)], 201);
    }

    public function show(string $organization, string $count): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);

        return response()->json(['data' => $this->full($company, $this->countIn($unit, $company, $count))]);
    }

    public function record(CountRequest $request, string $organization, string $count): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->countIn($unit, $company, $count);
        Gate::authorize('inventory.manage', $this->unitOf($this->found(Warehouse::class, $company, $found->warehouse_id, 'warehouse')->unit_id));

        return response()->json(['data' => $this->full($company, $this->counts->record($company, $found, (int) $request->validated('base_version'), $request->validated('lines'), $request->user()))]);
    }

    public function step(CountRequest $request, string $organization, string $count, string $step): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->countIn($unit, $company, $count);
        $permission = ['submit' => 'inventory.manage', 'cancel' => 'inventory.manage', 'approve' => 'inventory.approve', 'reject' => 'inventory.approve'][$step] ?? throw InventoryException::unknownStep();
        Gate::authorize($permission, $this->unitOf($this->found(Warehouse::class, $company, $found->warehouse_id, 'warehouse')->unit_id));
        $version = (int) $request->validated('base_version');

        $changed = match ($step) {
            'submit' => $this->counts->submit($company, $found, $version, $request->user()),
            'approve' => $this->counts->approve($company, $found, $version, $request->user()),
            'reject' => $this->counts->reject($company, $found, $version, (string) $request->validated('reason'), $request->user()),
            'cancel' => $this->counts->cancel($company, $found, $version, $request->user()),
        };

        return response()->json(['data' => $this->full($company, $changed)]);
    }

    private function countIn(Organization $unit, Organization $company, string $id): StockCount
    {
        $count = $this->inventories->query(StockCount::class, $company)->whereKey($id)->first();
        if ($count === null || ! in_array($count->warehouse_id, $this->warehouseIds($unit, $company), true)) {
            throw InventoryException::notFound('count');
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function full(Organization $company, StockCount $count): array
    {
        $at = $this->unitOf($this->found(Warehouse::class, $company, $count->warehouse_id, 'warehouse')->unit_id);
        $manages = Gate::allows('inventory.manage', $at);
        $approves = Gate::allows('inventory.approve', $at) && ! in_array(auth()->id(), [$count->created_by, $count->submitted_by], true);

        return $this->presenter->count($count, $this->counts->linesOf($company, $count), [
            'record' => $count->status === StockCount::COUNTING && $manages,
            'submit' => $count->status === StockCount::COUNTING && $manages,
            'approve' => $count->status === StockCount::PENDING && $approves,
            'reject' => $count->status === StockCount::PENDING && $approves,
            'cancel' => in_array($count->status, [StockCount::COUNTING, StockCount::PENDING], true) && $manages,
        ]);
    }
}
