<?php

namespace Modules\FactoryErp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Http\Controllers\Concerns\FindsFactory;
use Modules\FactoryErp\Http\FactoryPresenter;
use Modules\FactoryErp\Http\Requests\DefectTypeRequest;
use Modules\FactoryErp\Http\Requests\InspectionRequest;
use Modules\FactoryErp\Models\DefectType;
use Modules\FactoryErp\Models\Inspection;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Style;
use Modules\FactoryErp\Services\Floor;
use Modules\FactoryErp\Services\Lines;
use Modules\FactoryErp\Services\Quality;

/**
 * Quality: the company's kinds of defect (set up with factory_erp.manage),
 * a day's inspections with what the screen needs to write more, an
 * order's quality, and inspections written and voided with factory_erp.qc;
 * read with factory_erp.view.
 */
class QcController extends Controller
{
    use FindsFactory;

    public function __construct(
        private Floor $floor,
        private Quality $quality,
        private Lines $lines,
        private FactoryPresenter $presenter,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function defectTypes(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);

        return response()->json(['data' => $this->quality->defectTypes($company)->map(fn (DefectType $type) => $this->presenter->defectType($type))->values()]);
    }

    public function storeDefectType(DefectTypeRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.manage', $company);

        return response()->json(['data' => $this->presenter->defectType($this->quality->saveDefectType($company, null, $request->validated(), $request->user()))], 201);
    }

    public function updateDefectType(DefectTypeRequest $request, string $organization, string $type): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->floor->query(DefectType::class, $company)->whereKey($type)->first() ?? throw FactoryException::notFound('defect_type');
        Gate::authorize('factory_erp.manage', $company);

        return response()->json(['data' => $this->presenter->defectType($this->quality->saveDefectType($company, $found, $request->validated(), $request->user()))]);
    }

    /** A day's inspections at the unit, with the kinds of defect, lines and orders in production to write for, and the AQL table in use. */
    public function inspections(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $day = isset($filters['date']) ? CarbonImmutable::parse($filters['date'], 'UTC') : $this->floor->today($company);
        $units = $this->floor->subtreeIds($unit);
        $inspections = $this->floor->query(Inspection::class, $company)->whereIn('unit_id', $units)->where('inspected_on', $day->toDateString())
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $defects = $this->quality->defectsOf($company, $inspections->modelKeys());
        $orders = $this->floor->query(Order::class, $company)->whereIn('unit_id', $units)
            ->where(fn ($query) => $query->where('status', 'in_production')->orWhereIn('id', $inspections->pluck('order_id')))->orderBy('ship_on')->get();
        $styles = $this->floor->query(Style::class, $company)->whereKey($orders->pluck('style_id')->unique())->pluck('code', 'id');
        $aql = (string) $this->rules->get('factory_erp.final_aql', $this->contexts->forOrganization($unit));

        return response()->json([
            'data' => $inspections->map(fn (Inspection $inspection) => $this->presenter->inspection($inspection, $defects[$inspection->getKey()] ?? collect()))->values(),
            'meta' => [
                'date' => $day->toDateString(),
                'defect_types' => $this->quality->defectTypes($company)->where('is_active', true)->map(fn (DefectType $type) => $this->presenter->defectType($type))->values(),
                'lines' => $this->lines->of($company, $unit, true)->map(fn ($line) => $this->presenter->line($line))->values(),
                'orders' => $orders->map(fn (Order $order) => ['id' => $order->getKey(), 'number' => $order->number, 'status' => $order->status, 'style_code' => $styles[$order->style_id] ?? null])->values(),
                'aql' => $aql,
                'aql_levels' => array_map(fn (array $level) => ['up_to' => $level[0], 'sample' => $level[1], 'accept' => $level[2][$aql] ?? null],
                    (require module_path('FactoryErp', 'database/data/aql.php'))['levels']),
            ],
        ]);
    }

    public function storeInspection(InspectionRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.qc', $unit);
        $inspection = $this->quality->record($company, $unit, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->inspection($inspection, $this->quality->defectsOf($company, [$inspection->getKey()])[$inspection->getKey()] ?? collect())],
            $inspection->wasRecentlyCreated ? 201 : 200);
    }

    public function voidInspection(Request $request, string $organization, string $inspection): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->floor->query(Inspection::class, $company)->whereKey($inspection)->whereIn('unit_id', $this->floor->subtreeIds($unit))->first()
            ?? throw FactoryException::notFound('inspection');
        Gate::authorize('factory_erp.qc', $unit);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:300']]);
        $voided = $this->quality->void($company, $found, (int) $data['base_version'], trim($data['reason']), $request->user());

        return response()->json(['data' => $this->presenter->inspection($voided, $this->quality->defectsOf($company, [$voided->getKey()])[$voided->getKey()] ?? collect())]);
    }

    /** An order's quality: end-line totals and defects by kind, and its final inspections. */
    public function orderQuality(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $found = $this->orderIn($unit, $company, $order);
        $finals = $this->floor->query(Inspection::class, $company)->where('order_id', $found->getKey())->where('kind', 'final')->orderByDesc('created_at')->orderByDesc('id')->get();
        $defects = $this->quality->defectsOf($company, $finals->modelKeys());

        return response()->json(['data' => [
            'endline' => $this->quality->endline($company, $found),
            'finals' => $finals->map(fn (Inspection $inspection) => $this->presenter->inspection($inspection, $defects[$inspection->getKey()] ?? collect()))->values(),
            'passed' => $this->quality->lastFinal($company, $found)?->result === 'pass',
            'ship_needs_pass' => (bool) $this->rules->get('factory_erp.ship_needs_final_pass', $this->contexts->forOrganization($company)),
        ]]);
    }
}
