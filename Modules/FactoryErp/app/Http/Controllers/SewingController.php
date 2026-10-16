<?php

namespace Modules\FactoryErp\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Http\Controllers\Concerns\FindsFactory;
use Modules\FactoryErp\Http\FactoryPresenter;
use Modules\FactoryErp\Http\Requests\LineRequest;
use Modules\FactoryErp\Http\Requests\OutputRequest;
use Modules\FactoryErp\Models\Line;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Output;
use Modules\FactoryErp\Models\Style;
use Modules\FactoryErp\Services\Floor;
use Modules\FactoryErp\Services\Lines;
use Modules\FactoryErp\Services\Outputs;

/**
 * Sewing lines of the unit and the units below it (set up with
 * factory_erp.manage), and what they sew: a day's entries with the lines
 * and orders in production to write for (factory_erp.view), entries
 * written and voided with factory_erp.produce.
 */
class SewingController extends Controller
{
    use FindsFactory;

    public function __construct(private Floor $floor, private Lines $lines, private Outputs $outputs, private FactoryPresenter $presenter) {}

    public function lines(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $active = $request->validate(['active' => ['nullable', 'boolean']])['active'] ?? false;

        return response()->json(['data' => $this->lines->of($company, $unit, (bool) $active)->map(fn (Line $line) => $this->presenter->line($line))->values()]);
    }

    public function storeLine(LineRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.manage', $unit);

        return response()->json(['data' => $this->presenter->line($this->lines->save($company, $unit, null, $request->validated(), $request->user()))], 201);
    }

    public function updateLine(LineRequest $request, string $organization, string $line): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->floor->query(Line::class, $company)->whereKey($line)->whereIn('unit_id', $this->floor->subtreeIds($unit))->first()
            ?? throw FactoryException::notFound('line');
        Gate::authorize('factory_erp.manage', $unit);

        return response()->json(['data' => $this->presenter->line($this->lines->save($company, $unit, $found, $request->validated(), $request->user()))]);
    }

    /** A day's entries, with what the screen needs to write more: lines in use and orders in production. */
    public function outputs(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'line_id' => ['nullable', 'string', 'size:26']]);
        $day = isset($filters['date']) ? CarbonImmutable::parse($filters['date'], 'UTC') : $this->floor->today($company);
        $entries = $this->outputs->ofDay($company, $unit, $day, $filters['line_id'] ?? null);
        $lines = $this->outputs->lines($company, $entries->modelKeys());
        $orders = $this->floor->query(Order::class, $company)->whereIn('unit_id', $this->floor->subtreeIds($unit))
            ->where(fn ($query) => $query->where('status', 'in_production')->orWhereIn('id', $entries->pluck('order_id')))->orderBy('ship_on')->get();
        $styles = $this->floor->query(Style::class, $company)->whereKey($orders->pluck('style_id')->unique())->get()->keyBy('id');

        return response()->json([
            'data' => $entries->map(fn (Output $output) => $this->presenter->output($output, $lines[$output->getKey()] ?? collect()))->values(),
            'meta' => [
                'date' => $day->toDateString(),
                'lines' => $this->lines->of($company, $unit)->map(fn (Line $line) => $this->presenter->line($line))->values(),
                'orders' => $orders->map(fn (Order $order) => [
                    'id' => $order->getKey(), 'number' => $order->number, 'status' => $order->status, 'ship_on' => $order->ship_on->toDateString(),
                    'style_code' => $styles[$order->style_id]?->code, 'style_name' => $styles[$order->style_id]?->name,
                    'sizes' => $styles[$order->style_id]?->sizes ?? [], 'colors' => $styles[$order->style_id]?->colors ?? [],
                ])->values(),
            ],
        ]);
    }

    public function storeOutput(OutputRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.produce', $unit);
        $output = $this->outputs->record($company, $unit, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->output($output, $this->outputs->lines($company, [$output->getKey()])[$output->getKey()] ?? collect())],
            $output->wasRecentlyCreated ? 201 : 200);
    }

    public function voidOutput(Request $request, string $organization, string $output): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->floor->query(Output::class, $company)->whereKey($output)->whereIn('unit_id', $this->floor->subtreeIds($unit))->first()
            ?? throw FactoryException::notFound('output');
        Gate::authorize('factory_erp.produce', $unit);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:300']]);
        $voided = $this->outputs->void($company, $found, (int) $data['base_version'], trim($data['reason']), $request->user());

        return response()->json(['data' => $this->presenter->output($voided, $this->outputs->lines($company, [$voided->getKey()])[$voided->getKey()] ?? collect())]);
    }
}
