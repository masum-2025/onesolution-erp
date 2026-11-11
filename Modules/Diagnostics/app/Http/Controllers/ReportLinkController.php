<?php

namespace Modules\Diagnostics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Http\Controllers\Concerns\FindsDiagnostics;
use Modules\Diagnostics\Http\Requests\LabActionRequest;
use Modules\Diagnostics\Models\Order;
use Modules\Diagnostics\Services\DiagnosticsStore;
use Modules\Diagnostics\Services\ReportLinks;

/**
 * A time-limited report link for a verified order, with diagnostics.deliver:
 * shown to copy, and sent to the patient's mobile when asked. The token is
 * in this answer only.
 */
class ReportLinkController extends Controller
{
    use FindsDiagnostics;

    public function __construct(private DiagnosticsStore $store, private ReportLinks $links) {}

    public function store(LabActionRequest $request, string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->store->query(Order::class, $company)->whereKey($order)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw DiagnosticsException::notFound('order');
        Gate::authorize('diagnostics.deliver', $unit);

        return response()->json(['data' => $this->links->make($company, $found, (bool) $request->validated('sms', false), $request->user())], 201);
    }
}
