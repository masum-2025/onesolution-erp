<?php

namespace Modules\PatientBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Http\Controllers\Concerns\FindsPatientBilling;
use Modules\PatientBilling\Http\PatientBillingPresenter;
use Modules\PatientBilling\Http\Requests\DecisionRequest;
use Modules\PatientBilling\Models\CashDay;
use Modules\PatientBilling\Services\CashDays;
use Modules\PatientBilling\Services\PatientBillingStore;

/**
 * Cash days: a cashier (patient_billing.collect) opens and closes their own
 * at the unit; a supervisor (patient_billing.close_day) sees everyone's at
 * the unit and below and accepts a day whose difference was over the limit.
 */
class CashDayController extends Controller
{
    use FindsPatientBilling;

    public function __construct(private PatientBillingStore $store, private CashDays $days, private PatientBillingPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.collect', $unit);
        $mine = $this->days->openDay($company, $unit, $request->user());
        $all = Gate::allows('patient_billing.close_day', $unit);
        $days = $this->store->query(CashDay::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->when(! $all, fn ($query) => $query->where('user_id', $request->user()->getKey()))
            ->orderByDesc('opened_at')->limit(60)->get()
            // Days waiting for review first, then the newest.
            ->sortBy(fn (CashDay $day) => $day->status === CashDay::REVIEW ? 0 : 1)->values();
        $names = $this->presenter->userNames($days->pluck('user_id')->all());

        return response()->json(['data' => [
            'current' => $mine === null ? null : $this->presenter->cashDay($mine, $names, $this->days->expected($company, $mine)),
            'can_review' => $all,
            'variance_allowed_minor' => (int) $this->store->rule('cash_variance_allowed', $unit),
            'days' => $days->map(fn (CashDay $day) => $this->presenter->cashDay($day, $names, $day->status === CashDay::OPEN ? $this->days->expected($company, $day) : null))->values(),
        ]]);
    }

    public function open(DecisionRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.collect', $unit);
        $day = $this->days->open($company, $unit, (int) ($request->validated('opening_float_minor') ?? 0), $request->user());

        return response()->json(['data' => $this->presenter->cashDay($day, [], $this->days->expected($company, $day))], 201);
    }

    public function close(DecisionRequest $request, string $organization, string $day): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.collect', $unit);
        $closed = $this->days->close($company, $unit, $this->find($unit, $company, $day), (int) $request->validated('counted_minor'), (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->presenter->cashDay($closed)]);
    }

    public function review(DecisionRequest $request, string $organization, string $day): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('patient_billing.close_day', $unit);
        $closed = $this->days->review($company, $this->find($unit, $company, $day), $request->validated('note'), $request->user());

        return response()->json(['data' => $this->presenter->cashDay($closed)]);
    }

    private function find($unit, $company, string $id): CashDay
    {
        return $this->store->query(CashDay::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw PatientBillingException::notFound('cash_day');
    }
}
