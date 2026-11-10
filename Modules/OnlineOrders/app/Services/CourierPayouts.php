<?php

namespace Modules\OnlineOrders\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\OnlineOrders\Exceptions\OnlineOrdersException;
use Modules\OnlineOrders\Models\Courier;
use Modules\OnlineOrders\Models\CourierPayout;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Models\OrderPayment;

/**
 * SC-6: what each courier account owes the company (cash it collected at
 * the door, see Orders::courierSays) and the payouts that settle it. A
 * payout names the payments it settles, what arrived and where, and the
 * courier's charge; a difference needs a reason. Into the books: the money
 * account and the charge against the courier clearing account (Accounting
 * on). Written once (op id), never changed.
 */
class CourierPayouts
{
    public function __construct(private OrderBook $book, private OrderPostings $postings, private AuditLogger $audit) {}

    /**
     * Payments the courier collected and has not paid out yet, oldest first.
     *
     * @return Collection<int, array{id: string, order_id: string, number: string, courier_ref: ?string, amount_minor: int, currency: string, collected_at: string}>
     */
    public function owed(Organization $company, Courier $courier): Collection
    {
        $orders = $this->book->query(Order::class, $company)->where('courier_id', $courier->getKey());
        $payments = $this->book->query(OrderPayment::class, $company)->where('method', OrderPayment::COURIER)->where('kind', 'payment')
            ->whereNull('courier_payout_id')->whereIn('order_id', (clone $orders)->select('id'))
            ->orderBy('created_at')->orderBy('id')->get(['id', 'order_id', 'amount_minor', 'created_at']);
        $numbers = $payments->isEmpty() ? collect() : (clone $orders)->whereIn('id', $payments->pluck('order_id')->unique()->all())->get(['id', 'number', 'courier_ref', 'currency_code'])->keyBy('id');

        return $payments->map(fn (OrderPayment $payment) => [
            'id' => (string) $payment->getKey(), 'order_id' => (string) $payment->order_id,
            'number' => (string) $numbers[$payment->order_id]?->number, 'courier_ref' => $numbers[$payment->order_id]?->courier_ref,
            'amount_minor' => (int) $payment->amount_minor, 'currency' => (string) $numbers[$payment->order_id]?->currency_code,
            'collected_at' => $payment->created_at->toIso8601String(),
        ])->values();
    }

    /** @return Collection<int, CourierPayout> the latest first */
    public function history(Organization $company, Courier $courier, int $limit = 100): Collection
    {
        return $this->book->query(CourierPayout::class, $company)->where('courier_id', $courier->getKey())
            ->orderByDesc('paid_on')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * @param  array{op_id: string, payment_ids: list<string>, method: string, received_minor: int, fee_minor?: int, paid_on: string, reference?: string|null, reason?: string|null}  $data
     */
    public function record(Organization $company, Courier $courier, array $data, User $actor): CourierPayout
    {
        $done = $this->book->query(CourierPayout::class, $company)->where('op_id', $data['op_id'])->first();
        if ($done !== null) {
            return $done;
        }

        return $this->book->transaction($company, function () use ($company, $courier, $data, $actor) {
            $ids = array_values(array_unique($data['payment_ids']));
            $payments = $this->book->query(OrderPayment::class, $company)->whereKey($ids)->lockForUpdate()->get();
            $orders = $this->book->query(Order::class, $company)->whereKey($payments->pluck('order_id')->unique()->all())->get(['id', 'courier_id', 'currency_code'])->keyBy('id');
            $owed = $payments->filter(fn (OrderPayment $payment) => $payment->method === OrderPayment::COURIER && $payment->kind === 'payment'
                && $payment->courier_payout_id === null && $orders[$payment->order_id]?->courier_id === $courier->getKey());
            if ($owed->count() !== count($ids)) {
                throw OnlineOrdersException::paymentsNotOwed();
            }
            $currencies = $owed->map(fn (OrderPayment $payment) => $orders[$payment->order_id]->currency_code)->unique();
            if ($currencies->count() !== 1) {
                throw OnlineOrdersException::mixedCurrencies();
            }
            $currency = (string) $currencies->first();
            $collected = (int) $owed->sum('amount_minor');
            $received = (int) $data['received_minor'];
            $fee = (int) ($data['fee_minor'] ?? 0);
            $difference = $collected - $received - $fee;
            $reason = trim((string) ($data['reason'] ?? '')) ?: null;
            if ($difference !== 0 && $reason === null) {
                throw OnlineOrdersException::differenceNeedsReason(MoneyText::shown($difference, $currency, app()->getLocale()));
            }

            $payout = new CourierPayout;
            $payout->forceFill([
                'organization_id' => $company->getKey(), 'courier_id' => $courier->getKey(), 'method' => $data['method'], 'currency_code' => $currency,
                'collected_minor' => $collected, 'received_minor' => $received, 'fee_minor' => $fee, 'difference_minor' => $difference,
                'payments_count' => $owed->count(), 'paid_on' => $data['paid_on'], 'reference' => $data['reference'] ?? null, 'reason' => $reason,
                'recorded_by' => $actor->getKey(), 'op_id' => $data['op_id'], 'created_at' => CarbonImmutable::now(),
            ])->save();
            $this->book->query(OrderPayment::class, $company)->whereKey($owed->modelKeys())->update(['courier_payout_id' => $payout->getKey()]);

            // Short: an expense; more than owed: the other way. Both on the courier difference key.
            $journal = $this->postings->post($company, "online-order-courier-payout-{$payout->getKey()}", CarbonImmutable::parse($data['paid_on']),
                __('online_orders::orders.narration.courier_payout', ['courier' => $courier->name, 'count' => $owed->count()]), 'courier_payout', $payout->getKey(), $currency, [
                    LedgerLine::debit(OrderPostings::METHOD_KEYS[$data['method']], $received),
                    LedgerLine::debit('online_orders.courier_fees', $fee),
                    $difference > 0 ? LedgerLine::debit('online_orders.courier_difference', $difference) : LedgerLine::credit('online_orders.courier_difference', -$difference),
                    LedgerLine::credit('online_orders.courier_clearing', $collected),
                ]);
            $payout->forceFill(['journal_id' => $journal])->save();
            $this->audit->record('online_orders.courier_payout_recorded', $payout, new: [
                'courier' => $courier->name, 'payments' => $owed->count(), 'collected_minor' => $collected, 'received_minor' => $received,
                'fee_minor' => $fee, 'difference_minor' => $difference, 'currency' => $currency, 'method' => $data['method'], 'reason' => $reason,
            ], actor: $actor, organizationId: $company->getKey());

            return $payout;
        });
    }
}
