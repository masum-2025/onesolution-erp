<?php

namespace Modules\Diagnostics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Http\Controllers\Concerns\FindsDiagnostics;
use Modules\Diagnostics\Http\Requests\LabActionRequest;
use Modules\Diagnostics\Models\Order;
use Modules\Diagnostics\Models\OrderTest;
use Modules\Diagnostics\Services\DiagnosticsStore;
use Modules\Diagnostics\Services\LabOrders;
use Modules\Diagnostics\Services\ReportView;

/**
 * A test's results: entered with diagnostics.result, verified or sent back
 * with diagnostics.verify (never by the person who entered them).
 */
class ResultController extends Controller
{
    use FindsDiagnostics;

    public function __construct(private DiagnosticsStore $store, private LabOrders $orders, private ReportView $view) {}

    public function save(LabActionRequest $request, string $organization, string $test): JsonResponse
    {
        [$unit, $company, $line] = $this->line($organization, $test);
        Gate::authorize('diagnostics.result', $unit);
        $this->orders->saveResults($company, $unit, $line, $request->validated('values'), (int) $request->validated('base_version'), $request->user());

        return $this->order($company, $line);
    }

    public function verify(LabActionRequest $request, string $organization, string $test): JsonResponse
    {
        [$unit, $company, $line] = $this->line($organization, $test);
        Gate::authorize('diagnostics.verify', $unit);
        $this->orders->verify($company, $line, $request->validated('note'), $request->user());

        return $this->order($company, $line);
    }

    public function sendBack(LabActionRequest $request, string $organization, string $test): JsonResponse
    {
        [$unit, $company, $line] = $this->line($organization, $test);
        Gate::authorize('diagnostics.verify', $unit);
        $this->orders->sendBack($company, $line, $request->validated('reason'), $request->user());

        return $this->order($company, $line);
    }

    /** @return array{0: Organization, 1: Organization, 2: OrderTest} */
    private function line(string $organization, string $id): array
    {
        [$unit, $company] = $this->workplace($organization);
        $line = $this->store->query(OrderTest::class, $company)->whereKey($id)->first();
        if ($line === null || ! $this->store->query(Order::class, $company)->whereKey($line->order_id)->whereIn('unit_id', $this->store->subtreeIds($unit))->exists()) {
            throw DiagnosticsException::notFound('order_test');
        }

        return [$unit, $company, $line];
    }

    private function order(Organization $company, OrderTest $line): JsonResponse
    {
        return response()->json(['data' => $this->view->full($company, $this->store->query(Order::class, $company)->findOrFail($line->order_id))]);
    }
}
