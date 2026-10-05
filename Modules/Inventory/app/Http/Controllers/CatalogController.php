<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Controllers\Concerns\FindsInventory;
use Modules\Inventory\Http\InventoryPresenter;
use Modules\Inventory\Http\Requests\CatalogRequest;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\Catalog;
use Modules\Inventory\Services\Inventories;

/**
 * Units, categories, warehouses and items: read (inventory.view at the unit
 * in the address), created and changed (inventory.manage at the company).
 */
class CatalogController extends Controller
{
    use FindsInventory;

    private const KINDS = [
        'units' => ['unit', Unit::class, 'unit'],
        'categories' => ['category', Category::class, 'category'],
        'warehouses' => ['warehouse', Warehouse::class, 'warehouse'],
        'items' => ['item', Item::class, 'item'],
    ];

    public function __construct(private Inventories $inventories, private Catalog $catalog, private InventoryPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('inventory.view', $unit);
        [, $class, $shape] = self::KINDS[$this->kind($request)];

        $query = $this->inventories->query($class, $company);
        if ($class === Warehouse::class) {
            $query->whereIn('unit_id', $this->inventories->subtreeIds($unit));
        }
        if ($class === Item::class) {
            $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'category_id' => ['nullable', 'string', 'max:26'], 'active' => ['nullable', 'boolean']]);
            if (! empty($filters['category_id'])) {
                $query->where('category_id', $filters['category_id']);
            }
            if (isset($filters['active'])) {
                $query->where('is_active', (bool) $filters['active']);
            }
            $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
            $rows = $query->orderBy('sku')->limit(2000)->get()
                ->filter(fn (Item $item) => $search === '' || str_contains(mb_strtolower($item->sku), $search) || $item->barcode === trim((string) $filters['search'])
                    || collect($item->texts('name'))->contains(fn ($text) => str_contains(mb_strtolower((string) $text), $search)))
                ->take(500);
        } else {
            $rows = $query->orderBy('code')->get();
        }

        return response()->json(['data' => $rows->map(fn ($model) => $this->presenter->{$shape}($model))->values(), 'meta' => ['currency' => $this->inventories->currency($company)]]);
    }

    public function store(CatalogRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('inventory.manage', $company);
        [$kind, , $shape] = self::KINDS[$request->kind()];

        return response()->json(['data' => $this->presenter->{$shape}($this->catalog->create($company, $kind, $request->safe()->except('base_version'), $request->user()))], 201);
    }

    public function update(CatalogRequest $request, string $organization, string $id): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('inventory.manage', $company);
        [$kind, $class, $shape] = self::KINDS[$request->kind()];
        $model = $this->found($class, $company, $id, $kind);
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->{$shape}($this->catalog->update($company, $kind, $model, (int) $data['base_version'], $data, $request->user()))]);
    }

    private function kind(Request $request): string
    {
        return explode('/', trim((string) preg_replace('#^.*/inventory/#', '', $request->path()), '/'))[0];
    }
}
