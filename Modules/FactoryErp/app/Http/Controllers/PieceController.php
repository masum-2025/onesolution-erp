<?php

namespace Modules\FactoryErp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Http\Controllers\Concerns\FindsFactory;
use Modules\FactoryErp\Http\FactoryPresenter;
use Modules\FactoryErp\Http\Requests\PieceTicketRequest;
use Modules\FactoryErp\Models\Operation;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\PieceTicket;
use Modules\FactoryErp\Models\Style;
use Modules\FactoryErp\Services\Floor;
use Modules\FactoryErp\Services\PieceRates;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * Operators' piece tickets at the unit: a day's tickets with what the
 * screen needs to write more (operators working that day, orders in
 * production with their operations and rates, each operator's month so
 * far), written and voided with factory_erp.produce. Operators' names are
 * shown only to people who write tickets.
 */
class PieceController extends Controller
{
    use FindsFactory;

    public function __construct(
        private Floor $floor,
        private PieceRates $pieceRates,
        private EmployeeDirectory $employees,
        private ModuleResolver $modules,
        private FactoryPresenter $presenter,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function tickets(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.view', $unit);
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $day = isset($filters['date']) ? CarbonImmutable::parse($filters['date'], 'UTC') : $this->floor->today($company);
        $units = $this->floor->subtreeIds($unit);
        $hrm = $this->modules->isEnabled('hrm', $company);
        $writes = Gate::allows('factory_erp.produce', $unit);
        $tickets = $this->pieceRates->ofDay($company, $unit, $day);
        $orders = $this->floor->query(Order::class, $company)->whereIn('unit_id', $units)->where('status', 'in_production')->orderBy('ship_on')->get();
        $styles = $this->floor->query(Style::class, $company)->whereKey($orders->pluck('style_id')->unique())->pluck('code', 'id');
        $operations = $this->floor->query(Operation::class, $company)->whereIn('style_id', $orders->pluck('style_id')->unique())->orderBy('sort_order')->get()->groupBy('style_id');
        $people = $hrm && $writes ? $this->employees->inUnits($company, $units, $day) : [];
        $named = $hrm && $writes ? $this->employees->many($company, $tickets->pluck('employee_id')->unique()->values()->all()) : [];

        return response()->json([
            'data' => $tickets->map(fn (PieceTicket $ticket) => $this->presenter->pieceTicket($ticket))->values(),
            'meta' => [
                'date' => $day->toDateString(),
                'hrm' => $hrm,
                'locked' => $this->pieceRates->locked($company, $day),
                'rate_per' => (string) $this->rules->get('factory_erp.piece_rate_per', $this->contexts->forOrganization($unit)),
                'currency' => rescue(fn () => $this->floor->currency($company), null, false),
                'employees' => array_map(fn (EmployeeRecord $employee) => ['id' => $employee->id, 'code' => $employee->code, 'name' => $employee->name],
                    [...$people, ...array_values(array_diff_key($named, array_flip(array_map(fn (EmployeeRecord $employee) => $employee->id, $people))))]),
                'orders' => $orders->map(fn (Order $order) => [
                    'id' => $order->getKey(), 'number' => $order->number, 'style_code' => $styles[$order->style_id] ?? null,
                    'operations' => ($operations[$order->style_id] ?? collect())->map(fn (Operation $operation) => $operation->only(['id', 'name', 'machine', 'piece_rate_minor']))->values(),
                ])->values(),
                'month' => $writes ? (object) $this->pieceRates->totals($company, null, $day->startOfMonth(), $day->endOfMonth(), $units) : (object) [],
            ],
        ]);
    }

    public function store(PieceTicketRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('factory_erp.produce', $unit);
        $ticket = $this->pieceRates->record($company, $unit, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->pieceTicket($ticket)], $ticket->wasRecentlyCreated ? 201 : 200);
    }

    public function void(Request $request, string $organization, string $ticket): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->floor->query(PieceTicket::class, $company)->whereKey($ticket)->whereIn('unit_id', $this->floor->subtreeIds($unit))->first()
            ?? throw FactoryException::notFound('ticket');
        Gate::authorize('factory_erp.produce', $unit);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:300']]);

        return response()->json(['data' => $this->presenter->pieceTicket($this->pieceRates->void($company, $found, (int) $data['base_version'], trim($data['reason']), $request->user()))]);
    }
}
