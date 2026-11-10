<?php

namespace Modules\Diagnostics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Http\Controllers\Concerns\FindsDiagnostics;
use Modules\Diagnostics\Http\Requests\LabActionRequest;
use Modules\Diagnostics\Http\Requests\OrderRequest;
use Modules\Diagnostics\Models\Order;
use Modules\Diagnostics\Models\Sample;
use Modules\Diagnostics\Services\DiagnosticsStore;
use Modules\Diagnostics\Services\LabOrders;
use Modules\Diagnostics\Services\ReportView;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Orders of the unit and below: the worklist by stage and opened with
 * diagnostics.view; placed with .order; samples taken and rejected with
 * .collect; reports read for printing with .view and handed over with
 * .deliver (only once verified). Anything outside the unit is the same 404.
 */
class OrderController extends Controller
{
    use FindsDiagnostics;

    public function __construct(private DiagnosticsStore $store, private LabOrders $orders, private ReportView $view) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.view', $unit);
        $filters = $request->validate([
            'stage' => ['nullable', 'in:'.implode(',', Order::STAGES)], 'q' => ['nullable', 'string', 'max:40'], 'patient' => ['nullable', 'string', 'size:26'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $term = strtoupper(trim((string) ($filters['q'] ?? '')));
        $bySample = $term === '' ? null : $this->store->query(Sample::class, $company)->where('barcode', $term)->value('order_id');
        $page = $this->store->query(Order::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when(isset($filters['stage']), fn ($query) => $query->where('status', $filters['stage']))
            ->when(isset($filters['patient']), fn ($query) => $query->where('patient_id', $filters['patient']))
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('number', $term)->when($bySample !== null, fn ($or) => $or->orWhereKey($bySample))))
            // The lab works oldest first; finished reports newest first.
            ->when(in_array($filters['stage'] ?? null, ['verified', 'delivered'], true) || ! isset($filters['stage']),
                fn ($query) => $query->orderByDesc('created_at'), fn ($query) => $query->orderBy('created_at'))
            ->orderBy('id')->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Order $order) => $this->view->summary($company, $order))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.view', $unit);

        return response()->json(['data' => $this->view->full($company, $this->find($unit, $company, $order))]);
    }

    public function store(OrderRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.order', $unit);
        $data = $request->validated();
        $order = $this->orders->place($company, $unit, $data['patient_id'], $data['test_ids'], $data['referrer_id'] ?? null, $data['notes'] ?? null, $data['op_id'] ?? null, $request->user());

        return response()->json(['data' => $this->view->full($company, $order->refresh())], $order->wasRecentlyCreated ? 201 : 200);
    }

    public function collect(Request $request, string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $order);
        Gate::authorize('diagnostics.collect', $unit);
        $this->orders->collect($company, $found, $request->user());

        return response()->json(['data' => $this->view->full($company, $found->refresh())]);
    }

    public function rejectSample(LabActionRequest $request, string $organization, string $sample): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.collect', $unit);
        $found = $this->sample($unit, $company, $sample);
        $this->orders->rejectSample($company, $found, $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->view->full($company, $this->store->query(Order::class, $company)->findOrFail($found->order_id))]);
    }

    /** Patients to order tests for: by patient number, mobile or name (through the Patients directory). */
    public function patients(Request $request, PatientDirectory $directory, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.order', $unit);
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        return response()->json(['data' => array_map(fn ($patient) => [
            'id' => $patient->id, 'mrn' => $patient->mrn, 'name' => $patient->name, 'sex' => $patient->sex, 'age' => $patient->age, 'mobile' => $patient->mobile,
        ], $directory->search($company, $term, 15))]);
    }

    /** The order a scanned tube belongs to. */
    public function bySample(string $organization, string $barcode): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.view', $unit);
        $sample = $this->store->query(Sample::class, $company)->where('barcode', strtoupper(trim($barcode)))->first();
        $order = $sample === null ? null : $this->store->query(Order::class, $company)->whereKey($sample->order_id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first();
        if ($order === null) {
            throw DiagnosticsException::notFound('sample');
        }

        return response()->json(['data' => ['order_id' => $order->getKey(), 'sample_id' => $sample->getKey(), 'rejected' => $sample->rejected_at !== null]]);
    }

    public function deliver(LabActionRequest $request, string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->find($unit, $company, $order);
        Gate::authorize('diagnostics.deliver', $unit);

        return response()->json(['data' => $this->view->full($company, $this->orders->deliver($company, $found, $request->validated('channel'), $request->user()))]);
    }

    /** The report for printing: verified values only, with the company and branch. */
    public function report(string $organization, string $order): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.view', $unit);
        $found = $this->find($unit, $company, $order);

        return response()->json(['data' => [
            ...$this->view->full($company, $found, verifiedOnly: true),
            'organization' => $company->displayName(),
            'branch' => Organization::query()->find($found->unit_id)?->displayName(),
        ]]);
    }

    private function find(Organization $unit, Organization $company, string $id): Order
    {
        return $this->store->query(Order::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw DiagnosticsException::notFound('order');
    }

    private function sample(Organization $unit, Organization $company, string $id): Sample
    {
        $sample = $this->store->query(Sample::class, $company)->whereKey($id)->first();
        if ($sample === null || ! $this->store->query(Order::class, $company)->whereKey($sample->order_id)->whereIn('unit_id', $this->store->subtreeIds($unit))->exists()) {
            throw DiagnosticsException::notFound('sample');
        }

        return $sample;
    }
}
