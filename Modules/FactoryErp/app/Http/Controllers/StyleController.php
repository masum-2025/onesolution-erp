<?php

namespace Modules\FactoryErp\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Crm\Services\Organizations;
use Modules\FactoryErp\Http\Controllers\Concerns\FindsFactory;
use Modules\FactoryErp\Http\FactoryPresenter;
use Modules\FactoryErp\Http\Requests\BomRequest;
use Modules\FactoryErp\Http\Requests\OperationsRequest;
use Modules\FactoryErp\Http\Requests\StyleRequest;
use Modules\FactoryErp\Models\Operation;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Style;
use Modules\FactoryErp\Services\Floor;
use Modules\FactoryErp\Services\Styles;

/**
 * The company's styles: read with factory_erp.view, made and changed (and
 * their bill of materials) with factory_erp.merchandise.
 */
class StyleController extends Controller
{
    use FindsFactory;

    public function __construct(private Floor $floor, private Styles $styles, private Organizations $buyers, private FactoryPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $filters = $request->validate(['buyer_id' => ['nullable', 'string', 'size:26'], 'q' => ['nullable', 'string', 'max:100'], 'active' => ['nullable', 'boolean']]);
        $needle = trim((string) ($filters['q'] ?? ''));
        $styles = $this->floor->query(Style::class, $company)
            ->when($filters['buyer_id'] ?? null, fn ($query, $id) => $query->where('buyer_id', $id))
            ->when($needle !== '', fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', '%'.addcslashes($needle, '%_\\').'%')->orWhere('name', 'like', '%'.addcslashes($needle, '%_\\').'%')))
            ->when(isset($filters['active']), fn ($query) => $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOL)))
            ->orderBy('code')->limit(500)->get();

        return response()->json([
            'data' => $styles->map(fn (Style $style) => $this->presenter->style($style))->values(),
            'meta' => ['buyers' => (object) $this->buyers->many($company, $styles->pluck('buyer_id')->unique()->values()->all())],
        ]);
    }

    public function show(string $organization, string $style): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);

        return $this->answer($company, $this->styleOf($company, $style));
    }

    public function store(StyleRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.merchandise', $unit);

        return $this->answer($company, $this->styles->save($company, null, $request->validated(), $request->user()), 201);
    }

    public function update(StyleRequest $request, string $organization, string $style): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->styleOf($company, $style);
        Gate::authorize('factory_erp.merchandise', $unit);

        return $this->answer($company, $this->styles->save($company, $found, $request->validated(), $request->user()));
    }

    public function saveBom(BomRequest $request, string $organization, string $style): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->styleOf($company, $style);
        Gate::authorize('factory_erp.merchandise', $unit);
        $data = $request->validated();

        return $this->answer($company, $this->styles->saveBom($company, $found, (int) $data['base_version'], $data['lines'], $request->user()));
    }

    /** The operation bulletin; setting or changing a piece rate (pay) also needs factory_erp.manage at the company. */
    public function saveOperations(OperationsRequest $request, string $organization, string $style): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->styleOf($company, $style);
        Gate::authorize('factory_erp.merchandise', $unit);
        $data = $request->validated();
        if ($this->styles->ratesChange($company, $found, $data['lines'])) {
            Gate::authorize('factory_erp.manage', $company);
        }

        return $this->answer($company, $this->styles->saveOperations($company, $found, (int) $data['base_version'], $data['lines'], $request->user()));
    }

    private function answer($company, Style $style, int $status = 200): JsonResponse
    {
        $operations = $this->styles->operations($company, $style);

        return response()->json([
            'data' => [
                ...$this->presenter->style($style, $this->styles->bom($company, $style)),
                'operations' => $operations->map(fn (Operation $operation) => $operation->only(['id', 'name', 'machine', 'smv_milli', 'piece_rate_minor']))->values(),
                'smv_milli' => (int) $operations->sum('smv_milli'),
                'orders' => $this->floor->query(Order::class, $company)->where('style_id', $style->getKey())->count(),
            ],
            'meta' => ['buyer' => $this->buyers->many($company, [$style->buyer_id])[$style->buyer_id] ?? null],
        ], $status);
    }
}
