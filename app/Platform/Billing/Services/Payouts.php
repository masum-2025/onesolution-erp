<?php

namespace App\Platform\Billing\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\Exceptions\BillingException;
use App\Platform\Billing\Models\Commission;
use App\Platform\Billing\Models\Payout;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records a payment to a partner: every payable commission in one currency
 * (reversals included), after the money was sent. No currency conversion:
 * each currency is paid out on its own.
 */
class Payouts
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @return array<string, int> Payable total per currency.
     */
    public function payable(Partner $partner): array
    {
        return array_map(fn (array $row) => $row['total'], CurrencyTotals::of(
            Commission::query()->where('partner_id', $partner->getKey())->where('status', Commission::PAYABLE),
            'amount_minor',
        ));
    }

    public function record(Partner $partner, string $currency, string $reference, ?User $actor = null): Payout
    {
        return DB::transaction(function () use ($partner, $currency, $reference, $actor) {
            $commissions = Commission::query()
                ->where('partner_id', $partner->getKey())
                ->where('currency_code', $currency)
                ->where('status', Commission::PAYABLE)
                ->lockForUpdate()
                ->get();

            $total = (int) $commissions->sum('amount_minor');
            if ($total <= 0) {
                throw BillingException::nothingToPay($currency);
            }

            $payout = new Payout;
            $payout->forceFill([
                'partner_id' => $partner->getKey(),
                'currency_code' => $currency,
                'amount_minor' => $total,
                'reference' => $reference,
                'paid_at' => CarbonImmutable::now(),
                'recorded_by' => $actor?->getKey(),
            ])->save();

            Commission::query()->whereKey($commissions->modelKeys())->update([
                'status' => Commission::PAID,
                'payout_id' => $payout->getKey(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                action: 'billing.payout_recorded',
                target: $payout,
                new: ['currency' => $currency, 'amount_minor' => $total, 'commissions' => $commissions->count(), 'reference' => $reference],
                actor: $actor,
                partnerId: $partner->getKey(),
            );

            return $payout;
        });
    }
}
