<?php

namespace App\Platform\Billing\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\Models\WholesalePrice;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Wholesale prices: a partner's own price when it has one, else the default
 * for every partner; the row with the latest effective_from on or before the
 * day asked about. Prices are never edited, only replaced by a newer row.
 */
class WholesalePriceBook
{
    public function __construct(private AuditLogger $audit) {}

    public function find(Partner $partner, string $planKey, string $currency, CarbonImmutable $on): ?WholesalePrice
    {
        $latest = fn (?string $partnerId) => WholesalePrice::query()
            ->where('partner_id', $partnerId)
            ->where('plan_key', $planKey)
            ->where('currency_code', $currency)
            ->whereDate('effective_from', '<=', $on->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->first();

        return $latest($partner->getKey()) ?? $latest(null);
    }

    public function set(?Partner $partner, string $planKey, string $currency, string $unit, int $amountMinor, CarbonImmutable $from, ?User $actor = null, ?string $reason = null): WholesalePrice
    {
        return DB::transaction(function () use ($partner, $planKey, $currency, $unit, $amountMinor, $from, $actor, $reason) {
            $price = new WholesalePrice([
                'plan_key' => $planKey,
                'currency_code' => $currency,
                'unit' => $unit,
                'amount_minor' => $amountMinor,
                'effective_from' => $from->toDateString(),
            ]);
            $price->forceFill(['partner_id' => $partner?->getKey(), 'created_by' => $actor?->getKey()])->save();

            $this->audit->record(
                action: 'billing.wholesale_price_set',
                target: $price,
                new: ['plan' => $planKey, 'currency' => $currency, 'unit' => $unit, 'amount_minor' => $amountMinor, 'effective_from' => $from->toDateString()],
                reason: $reason,
                actor: $actor,
                partnerId: $partner?->getKey(),
            );

            return $price;
        });
    }

    /**
     * Record the data file's prices as the defaults. A price that already
     * applies is left alone; a changed one starts today. Returns rows added.
     *
     * @param  list<array{plan: string, currency: string, unit: string, amount_minor: int}>  $entries
     */
    public function syncDefaults(array $entries): int
    {
        $added = 0;

        foreach ($entries as $entry) {
            $current = WholesalePrice::query()
                ->whereNull('partner_id')
                ->where('plan_key', $entry['plan'])
                ->where('currency_code', $entry['currency'])
                ->orderByDesc('effective_from')
                ->orderByDesc('created_at')
                ->first();

            if ($current !== null && $current->unit === $entry['unit'] && $current->amount_minor === $entry['amount_minor']) {
                continue;
            }

            // The very first default covers the past too, so earlier months can be billed.
            $from = $current === null ? CarbonImmutable::create(2000, 1, 1) : CarbonImmutable::today();
            $this->set(null, $entry['plan'], $entry['currency'], $entry['unit'], $entry['amount_minor'], $from, reason: 'Default wholesale prices (data file)');
            $added++;
        }

        return $added;
    }
}
