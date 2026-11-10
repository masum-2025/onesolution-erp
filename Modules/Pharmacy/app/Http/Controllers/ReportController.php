<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Pharmacy\Http\Controllers\Concerns\FindsPharmacy;
use Modules\Pharmacy\Models\SaleLink;
use Modules\Pharmacy\Services\PharmacyStock;
use Modules\Pharmacy\Services\PharmacyStore;

/**
 * Pharmacy reports at the unit and below (pharmacy.approve): sales per day
 * (count, total, with controlled medicines), and stock by expiry (batches and
 * their worth at MRP: expired, expiring soon, due back to the supplier).
 */
class ReportController extends Controller
{
    use FindsPharmacy;

    public function __construct(private PharmacyStore $store, private PharmacyStock $stock) {}

    public function __invoke(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('pharmacy.approve', $unit);
        $filters = $request->validate([
            'kind' => ['required', 'in:sales,expiry'],
            'from' => ['required_if:kind,sales', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:kind,sales', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $currency = rescue(fn () => $this->store->currency($company), null, false);

        if ($filters['kind'] === 'expiry') {
            $rows = collect($this->stock->batches($company, $unit))->where('state', '!=', 'ok')->groupBy('state')
                ->map(fn ($batches, $state) => ['state' => $state, 'batches' => $batches->count(),
                    'worth_minor' => (int) $batches->sum(fn (array $batch) => intdiv($batch['quantity_milli'], 1000) * $batch['mrp_minor'])])
                ->values();

            return response()->json(['data' => ['kind' => 'expiry', 'rows' => $rows, 'currency' => $currency]]);
        }

        if (CarbonImmutable::parse($filters['from'])->diffInDays(CarbonImmutable::parse($filters['to'])) > 366) {
            return response()->json(['message' => __('pharmacy::messages.validation.range'), 'errors' => ['to' => [__('pharmacy::messages.validation.range')]]], 422);
        }
        $timezone = $this->store->timezone($company);
        $from = CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc();
        $to = CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()->utc();
        $rows = $this->store->query(SaleLink::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))->whereBetween('sold_at', [$from, $to])
            ->get(['sold_at', 'total_minor', 'has_controlled'])
            ->groupBy(fn (SaleLink $link) => $link->sold_at->setTimezone($timezone)->toDateString())
            ->map(fn ($links, $day) => ['day' => $day, 'sales' => $links->count(), 'total_minor' => (int) $links->sum('total_minor'), 'controlled' => $links->where('has_controlled', true)->count()])
            ->sortKeys()->values();

        return response()->json(['data' => ['kind' => 'sales', 'rows' => $rows, 'total_minor' => (int) $rows->sum('total_minor'), 'currency' => $currency]]);
    }
}
