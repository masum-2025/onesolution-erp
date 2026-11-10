<?php

namespace Modules\OnlineOrders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\OnlineOrders\Http\Requests\CourierPayoutRequest;
use Modules\OnlineOrders\Models\Courier;
use Modules\OnlineOrders\Models\CourierPayout;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Services\CourierPayouts;
use Modules\OnlineOrders\Services\Couriers;
use Modules\OnlineOrders\Services\OrderBook;

/**
 * SC-6: what each courier account owes the company and the payouts that
 * settle it, with online_orders.courier_payouts at the company (only
 * there: accounts are the company's).
 */
class CourierPayoutController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private OrderBook $book, private Couriers $couriers, private CourierPayouts $payouts) {}

    /** Every account with what it owes now (per currency) and its last payout. */
    public function index(string $organization): JsonResponse
    {
        $company = $this->company($organization);

        return response()->json(['data' => $this->couriers->accounts($company)->map(function (Courier $courier) use ($company) {
            $owed = $this->payouts->owed($company, $courier);
            $last = $this->payouts->history($company, $courier, 1)->first();

            return [
                'id' => $courier->getKey(), 'name' => $courier->name, 'driver' => $courier->driver, 'is_active' => $courier->is_active,
                'owed' => $owed->groupBy('currency')->map(fn ($rows, $currency) => ['currency' => $currency, 'amount_minor' => (int) $rows->sum('amount_minor'), 'count' => $rows->count()])->values()->all(),
                'last_payout' => $last === null ? null : $this->shown($last),
                'returns' => $this->returns($company, $courier),
            ];
        })->values()->all()]);
    }

    /** One account: the payments it owes (oldest first) and its payouts (latest first). */
    public function show(string $organization, string $courier): JsonResponse
    {
        $company = $this->company($organization);
        $account = $this->couriers->find($company, $courier);
        $history = $this->payouts->history($company, $account);
        $people = User::query()->whereKey($history->pluck('recorded_by')->unique()->all())->pluck('name', 'id');

        return response()->json(['data' => [
            'id' => $account->getKey(), 'name' => $account->name, 'driver' => $account->driver,
            'owed' => $this->payouts->owed($company, $account)->all(),
            'payouts' => $history->map(fn (CourierPayout $payout) => [...$this->shown($payout), 'recorded_by' => $people[$payout->recorded_by] ?? null])->values()->all(),
        ]]);
    }

    public function store(CourierPayoutRequest $request, string $organization, string $courier): JsonResponse
    {
        $company = $this->company($organization);
        $payout = $this->payouts->record($company, $this->couriers->find($company, $courier), $request->validated(), $request->user());

        return response()->json(['data' => $this->shown($payout)], 201);
    }

    // ---------------------------------------------------------------- inside

    private function company(string $organization): Organization
    {
        $unit = $this->findVisible($organization);
        $company = $this->book->companyOf($unit);
        abort_unless($company->is($unit), 404);
        Gate::authorize('online_orders.courier_payouts', $company);

        return $company;
    }

    /**
     * SC-7: of the parcels sent with this account in the last 30 days, how many came (or are coming) back.
     *
     * @return array{days: int, sent: int, returned: int}
     */
    private function returns(Organization $company, Courier $courier): array
    {
        $sent = $this->book->query(Order::class, $company)->where('courier_id', $courier->getKey())->where('shipped_at', '>=', CarbonImmutable::now()->subDays(30))
            ->get(['id', 'courier_returning_at', 'received_back_at']);

        return ['days' => 30, 'sent' => $sent->count(), 'returned' => $sent->filter(fn (Order $order) => $order->courier_returning_at !== null || $order->received_back_at !== null)->count()];
    }

    /** @return array<string, mixed> */
    private function shown(CourierPayout $payout): array
    {
        return [
            'id' => $payout->getKey(), 'courier_id' => $payout->courier_id, 'method' => $payout->method, 'currency' => $payout->currency_code,
            'collected_minor' => $payout->collected_minor, 'received_minor' => $payout->received_minor, 'fee_minor' => $payout->fee_minor,
            'difference_minor' => $payout->difference_minor, 'payments_count' => $payout->payments_count, 'paid_on' => $payout->paid_on->toDateString(),
            'reference' => $payout->reference, 'reason' => $payout->reason, 'posted' => $payout->journal_id !== null, 'created_at' => $payout->created_at?->toIso8601String(),
        ];
    }
}
