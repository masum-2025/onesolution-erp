<?php

namespace Modules\Diagnostics\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Diagnostics\Events\CommissionDue;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Models\Commission;
use Modules\Diagnostics\Models\Order;
use Modules\Diagnostics\Models\OrderTest;
use Modules\Diagnostics\Models\Payout;
use Modules\Diagnostics\Models\Referrer;
use Modules\PatientBilling\Contracts\PatientCharges;

/**
 * Referring doctors' commission:
 *
 * - Earned when the patient's bill is final: for each order on it with a
 *   referring doctor, the doctor's percent (or the rule
 *   diagnostics.referral_commission_percent at the order's branch) of what
 *   the order's tests came to after discounts and without tax. Once per order.
 * - Taken back by a reversing line with a reason (a mistake, a refund), never
 *   by changing or removing the first.
 * - Paid out up to what is owed (no advances). Every line and payout is
 *   append-only, posted to the books when the company keeps them, and audited.
 */
class Commissions
{
    public function __construct(
        private DiagnosticsStore $store,
        private PatientCharges $charges,
        private DiagnosticsPostings $postings,
        private AuditLogger $audit,
    ) {}

    /** @return int How many orders earned commission. */
    public function accrueForBill(Organization $company, string $billId): int
    {
        $charged = $this->charges->chargesOn($company, $billId, 'diagnostics');
        if ($charged === null || $charged['status'] !== 'final' || $charged['lines'] === []) {
            return 0;
        }
        $lines = $this->store->query(OrderTest::class, $company)->whereKey(array_keys($charged['lines']))->get(['id', 'order_id']);
        $orders = $this->store->query(Order::class, $company)->whereKey($lines->pluck('order_id')->unique()->all())->whereNotNull('referrer_id')->get();
        $referrers = $this->store->query(Referrer::class, $company)->whereKey($orders->pluck('referrer_id')->unique()->all())->get()->keyBy('id');
        $currency = $charged['currency'] ?? $this->store->currency($company);
        $made = 0;
        foreach ($orders as $order) {
            $base = $lines->where('order_id', $order->getKey())->sum(fn (OrderTest $line) => $charged['lines'][$line->getKey()] ?? 0);
            $referrer = $referrers[$order->referrer_id] ?? null;
            $percent = $referrer?->commission_percent ?? (int) $this->store->rule('referral_commission_percent', Organization::query()->find($order->unit_id) ?? $company);
            $percent = max(0, min(100, (int) $percent));
            if ($referrer === null || $base <= 0 || $percent === 0) {
                continue;
            }
            $amount = intdiv($base * $percent + 50, 100);
            try {
                $commission = $this->store->transaction($company, function () use ($company, $order, $base, $percent, $amount, $currency) {
                    $commission = new Commission([
                        'organization_id' => $company->getKey(), 'unit_id' => $order->unit_id, 'referrer_id' => $order->referrer_id, 'order_id' => $order->getKey(),
                        'kind' => Commission::DUE, 'base_minor' => $base, 'percent' => $percent, 'amount_minor' => $amount, 'currency' => $currency,
                        'earned_on' => $this->store->today($company)->toDateString(), 'created_at' => CarbonImmutable::now(),
                    ]);
                    $commission->save();
                    $this->postings->post($company, 'diagnostics-commission-'.$commission->getKey(), $this->store->today($company),
                        __('diagnostics::messages.postings.commission', ['number' => $order->number], 'en'), 'commission', $commission->getKey(), $currency, [
                            LedgerLine::debit('diagnostics.commission_expense', $amount, $order->unit_id),
                            LedgerLine::credit('diagnostics.commission_payable', $amount, $order->unit_id),
                        ]);
                    $this->audit->record('diagnostics.commission_earned', $commission, new: ['number' => $order->number, 'percent' => $percent, 'amount_minor' => $amount], organizationId: $company->getKey());

                    return $commission;
                });
            } catch (UniqueConstraintViolationException) {
                continue; // Earned already (the bill's event came twice).
            }
            $made++;
            CommissionDue::dispatch($company->getKey(), $commission->getKey(), $order->referrer_id, $order->getKey());
        }

        return $made;
    }

    /** Takes back one order's commission (once), with the reason on record. */
    public function reverse(Organization $company, Commission $due, string $reason, User $actor): Commission
    {
        if ($due->kind !== Commission::DUE) {
            throw DiagnosticsException::because('not_a_commission', 409);
        }
        try {
            return $this->store->transaction($company, function () use ($company, $due, $reason, $actor) {
                $this->lockReferrer($company, $due->referrer_id);
                if ($this->store->query(Commission::class, $company)->where('reverses_id', $due->getKey())->exists()) {
                    throw DiagnosticsException::because('already_reversed', 409);
                }
                if ($this->balance($company, $due->referrer_id) < $due->amount_minor) {
                    throw DiagnosticsException::because('commission_paid_out', 409);
                }
                $reversal = new Commission([
                    'organization_id' => $company->getKey(), 'unit_id' => $due->unit_id, 'referrer_id' => $due->referrer_id, 'order_id' => $due->order_id,
                    'kind' => Commission::REVERSAL, 'reverses_id' => $due->getKey(), 'base_minor' => -$due->base_minor, 'percent' => $due->percent,
                    'amount_minor' => -$due->amount_minor, 'currency' => $due->currency, 'earned_on' => $this->store->today($company)->toDateString(),
                    'reason' => $reason, 'created_by' => $actor->getKey(), 'created_at' => CarbonImmutable::now(),
                ]);
                $reversal->save();
                $number = $this->store->query(Order::class, $company)->whereKey($due->order_id)->value('number');
                $this->postings->post($company, 'diagnostics-commission-'.$reversal->getKey(), $this->store->today($company),
                    __('diagnostics::messages.postings.commission_reversed', ['number' => $number], 'en'), 'commission', $reversal->getKey(), $due->currency, [
                        LedgerLine::debit('diagnostics.commission_payable', $due->amount_minor, $due->unit_id),
                        LedgerLine::credit('diagnostics.commission_expense', $due->amount_minor, $due->unit_id),
                    ]);
                $this->audit->record('diagnostics.commission_reversed', $reversal, new: ['number' => $number, 'amount_minor' => $due->amount_minor], reason: $reason, actor: $actor, organizationId: $company->getKey());

                return $reversal;
            });
        } catch (UniqueConstraintViolationException) {
            throw DiagnosticsException::because('already_reversed', 409);
        }
    }

    /** Commission paid to a doctor, up to what is owed; the op id makes a repeat return the first. */
    public function pay(Organization $company, Organization $unit, Referrer $referrer, int $amountMinor, string $method, CarbonImmutable $paidOn, ?string $note, ?string $opId, User $actor): Payout
    {
        if ($opId !== null && ($done = $this->store->query(Payout::class, $company)->where('op_id', $opId)->first()) !== null) {
            return $done;
        }
        if ($paidOn->toDateString() > $this->store->today($company)->toDateString()) {
            throw DiagnosticsException::because('paid_in_future');
        }
        $currency = $this->store->currency($company);

        return $this->store->transaction($company, function () use ($company, $unit, $referrer, $amountMinor, $method, $paidOn, $note, $opId, $actor, $currency) {
            $this->lockReferrer($company, $referrer->getKey());
            $owed = $this->balance($company, $referrer->getKey());
            if ($amountMinor > $owed) {
                throw DiagnosticsException::because('pays_more_than_owed', 422, ['owed_minor' => $owed]);
            }
            $payout = new Payout([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'referrer_id' => $referrer->getKey(), 'amount_minor' => $amountMinor,
                'currency' => $currency, 'method' => $method, 'paid_on' => $paidOn->toDateString(), 'note' => $note, 'paid_by' => $actor->getKey(),
                'op_id' => $opId, 'created_at' => CarbonImmutable::now(),
            ]);
            $payout->save();
            $this->postings->post($company, 'diagnostics-payout-'.$payout->getKey(), $paidOn,
                __('diagnostics::messages.postings.payout', [], 'en'), 'payout', $payout->getKey(), $currency, [
                    LedgerLine::debit('diagnostics.commission_payable', $amountMinor, $unit->getKey()),
                    LedgerLine::credit(DiagnosticsPostings::METHOD_KEYS[$method], $amountMinor, $unit->getKey()),
                ]);
            $this->audit->record('diagnostics.commission_paid', $payout, new: ['amount_minor' => $amountMinor, 'method' => $method], actor: $actor, organizationId: $company->getKey());

            return $payout;
        });
    }

    /** What the doctor is owed now (earned less taken back less paid). */
    public function balance(Organization $company, string $referrerId): int
    {
        return (int) $this->store->query(Commission::class, $company)->where('referrer_id', $referrerId)->sum('amount_minor')
            - (int) $this->store->query(Payout::class, $company)->where('referrer_id', $referrerId)->sum('amount_minor');
    }

    /**
     * A doctor's account for a period: owed at the start, each commission line and payout, totals, owed at the end.
     *
     * @return array<string, mixed>
     */
    public function statement(Organization $company, Referrer $referrer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $id = $referrer->getKey();
        $before = fn (string $model, string $column) => (int) $this->store->query($model, $company)->where('referrer_id', $id)->where($column, '<', $from->toDateString())->sum('amount_minor');
        $opening = $before(Commission::class, 'earned_on') - $before(Payout::class, 'paid_on');
        $commissions = $this->store->query(Commission::class, $company)->where('referrer_id', $id)
            ->whereBetween('earned_on', [$from->toDateString(), $to->toDateString()])->orderBy('earned_on')->orderBy('id')->get();
        $payouts = $this->store->query(Payout::class, $company)->where('referrer_id', $id)
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->orderBy('paid_on')->orderBy('id')->get();
        $numbers = $this->store->query(Order::class, $company)->whereKey($commissions->pluck('order_id')->unique()->all())->pluck('number', 'id');
        $reversed = $this->store->query(Commission::class, $company)->where('referrer_id', $id)->where('kind', Commission::REVERSAL)
            ->whereIn('reverses_id', $commissions->modelKeys())->pluck('reverses_id')->flip();
        $earned = (int) $commissions->sum('amount_minor');
        $paid = (int) $payouts->sum('amount_minor');

        return [
            'referrer' => ['id' => $id, 'name' => $referrer->name, 'registration_no' => $referrer->registration_no, 'commission_percent' => $referrer->commission_percent],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $this->store->currency($company),
            'opening_minor' => $opening,
            'earned_minor' => $earned,
            'paid_minor' => $paid,
            'closing_minor' => $opening + $earned - $paid,
            'owed_minor' => $this->balance($company, $id),
            'commissions' => $commissions->map(fn (Commission $line) => [
                'id' => $line->getKey(), 'kind' => $line->kind, 'order_id' => $line->order_id, 'order_number' => $numbers[$line->order_id] ?? null,
                'earned_on' => $line->earned_on->toDateString(), 'base_minor' => $line->base_minor, 'percent' => $line->percent, 'amount_minor' => $line->amount_minor,
                'reason' => $line->reason, 'reversed' => $line->kind === Commission::DUE && isset($reversed[$line->getKey()]),
            ])->values()->all(),
            'payouts' => $payouts->map(fn (Payout $payout) => [
                'id' => $payout->getKey(), 'paid_on' => $payout->paid_on->toDateString(), 'amount_minor' => $payout->amount_minor, 'method' => $payout->method, 'note' => $payout->note,
            ])->values()->all(),
        ];
    }

    /** One payout or reversal at a time per doctor, so what is owed cannot go below nothing. */
    private function lockReferrer(Organization $company, string $referrerId): void
    {
        $this->store->query(Referrer::class, $company)->whereKey($referrerId)->lockForUpdate()->first();
    }
}
