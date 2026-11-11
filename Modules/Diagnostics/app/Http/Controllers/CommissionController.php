<?php

namespace Modules\Diagnostics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Http\Controllers\Concerns\FindsDiagnostics;
use Modules\Diagnostics\Http\Requests\CommissionRequest;
use Modules\Diagnostics\Models\Commission;
use Modules\Diagnostics\Models\Payout;
use Modules\Diagnostics\Models\Referrer;
use Modules\Diagnostics\Services\Commissions;
use Modules\Diagnostics\Services\DiagnosticsStore;

/**
 * Referring doctors' commission at the unit and below, all with
 * diagnostics.commission: who is owed what, one doctor's account for a
 * period, paying them and taking an order's commission back. Doctors and
 * lines outside the unit are the same 404.
 */
class CommissionController extends Controller
{
    use FindsDiagnostics;

    public function __construct(private DiagnosticsStore $store, private Commissions $commissions) {}

    /** Doctors with commission owed or earned in the period, largest owed first. */
    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.commission', $unit);
        [$from, $to] = $this->period($request, $company);
        $referrers = $this->store->query(Referrer::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))->get()->keyBy('id');
        $ids = $referrers->keys()->all();
        $totals = function (string $model, ?array $between = null) use ($company, $ids) {
            $sums = [];
            $this->store->query($model, $company)->whereIn('referrer_id', $ids)
                ->when($between !== null, fn ($query) => $query->whereBetween('earned_on', $between))
                ->get(['referrer_id', 'amount_minor'])
                ->each(function ($row) use (&$sums) {
                    $sums[$row->referrer_id] = ($sums[$row->referrer_id] ?? 0) + $row->amount_minor;
                });

            return $sums;
        };
        $earned = $totals(Commission::class, [$from->toDateString(), $to->toDateString()]);
        $owing = $totals(Commission::class);
        $paid = $totals(Payout::class);
        $rows = collect($ids)->map(fn (string $id) => [
            'id' => $id, 'name' => $referrers[$id]->name, 'registration_no' => $referrers[$id]->registration_no, 'commission_percent' => $referrers[$id]->commission_percent,
            'earned_minor' => (int) ($earned[$id] ?? 0), 'owed_minor' => (int) ($owing[$id] ?? 0) - (int) ($paid[$id] ?? 0),
        ])->filter(fn (array $row) => $row['owed_minor'] !== 0 || $row['earned_minor'] !== 0)->sortByDesc('owed_minor')->values();

        return response()->json(['data' => $rows, 'meta' => [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => $this->store->currency($company),
            'owed_minor' => (int) $rows->sum('owed_minor'), 'earned_minor' => (int) $rows->sum('earned_minor'),
            'percent' => (int) $this->store->rule('referral_commission_percent', $unit),
        ]]);
    }

    public function show(Request $request, string $organization, string $referrer): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.commission', $unit);
        [$from, $to] = $this->period($request, $company);

        return response()->json(['data' => $this->commissions->statement($company, $this->referrer($unit, $company, $referrer), $from, $to)]);
    }

    public function pay(CommissionRequest $request, string $organization, string $referrer): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.commission', $unit);
        $found = $this->referrer($unit, $company, $referrer);
        $data = $request->validated();
        $payout = $this->commissions->pay($company, $unit, $found, (int) $data['amount_minor'], $data['method'], CarbonImmutable::parse($data['paid_on']),
            $data['note'] ?? null, $data['op_id'] ?? null, $request->user());

        return response()->json(['data' => ['id' => $payout->getKey(), 'owed_minor' => $this->commissions->balance($company, $found->getKey())]], $payout->wasRecentlyCreated ? 201 : 200);
    }

    public function reverse(CommissionRequest $request, string $organization, string $commission): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('diagnostics.commission', $unit);
        $due = $this->store->query(Commission::class, $company)->whereKey($commission)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw DiagnosticsException::notFound('commission');
        $this->commissions->reverse($company, $due, $request->validated('reason'), $request->user());

        return response()->json(['data' => ['owed_minor' => $this->commissions->balance($company, $due->referrer_id)]]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} This month unless from/to are given (at most a year). */
    private function period(Request $request, Organization $company): array
    {
        $dates = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $today = $this->store->today($company);
        $from = isset($dates['from']) ? CarbonImmutable::parse($dates['from']) : $today->startOfMonth();
        $to = isset($dates['to']) ? CarbonImmutable::parse($dates['to']) : $today->endOfMonth()->startOfDay();
        if ($from->diffInDays($to) > 366) {
            throw DiagnosticsException::because('period_too_long');
        }

        return [$from, $to];
    }

    private function referrer(Organization $unit, Organization $company, string $id): Referrer
    {
        return $this->store->query(Referrer::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw DiagnosticsException::notFound('referrer');
    }
}
