<?php

namespace App\Platform\Addons;

use App\Models\User;
use App\Platform\Addons\Models\AddonPrice;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly add-on prices: the row with the latest effective_from on or before
 * the day asked about. A client pays its partner's own list price when the
 * partner set one, else the platform's; a wholesale partner pays its own
 * wholesale deal, else the default. A module without a price cannot be bought.
 */
class AddonPriceBook
{
    public function __construct(private AuditLogger $audit) {}

    /** What a client of this partner pays per month, in minor units. */
    public function listPrice(Partner $partner, string $module, string $currency, ?CarbonImmutable $on = null): ?int
    {
        return $this->find(AddonPrice::LIST, $partner->getKey(), $module, $currency, $on ?? CarbonImmutable::today())?->amount_minor
            ?? $this->find(AddonPrice::LIST, null, $module, $currency, $on ?? CarbonImmutable::today())?->amount_minor;
    }

    /** What a wholesale partner pays us per client and month. */
    public function wholesalePrice(Partner $partner, string $module, string $currency, ?CarbonImmutable $on = null): ?int
    {
        return $this->find(AddonPrice::WHOLESALE, $partner->getKey(), $module, $currency, $on ?? CarbonImmutable::today())?->amount_minor
            ?? $this->find(AddonPrice::WHOLESALE, null, $module, $currency, $on ?? CarbonImmutable::today())?->amount_minor;
    }

    /** The platform's own list price (what a partner's clients pay unless it set its own). */
    public function platformListPrice(string $module, string $currency): ?int
    {
        return $this->find(AddonPrice::LIST, null, $module, $currency, CarbonImmutable::today())?->amount_minor;
    }

    /** The platform's own wholesale price (what a wholesale partner pays us, unless agreed otherwise for it). */
    public function platformWholesalePrice(string $module, string $currency): ?int
    {
        return $this->find(AddonPrice::WHOLESALE, null, $module, $currency, CarbonImmutable::today())?->amount_minor;
    }

    /**
     * Every currency add-ons are priced in, by the platform or this partner
     * (and the partner's billing currency): the columns a price table shows.
     *
     * @return list<string>
     */
    public function knownCurrencies(?Partner $partner, ?string $also = null): array
    {
        $codes = AddonPrice::query()
            ->where(fn ($query) => $query->whereNull('partner_id')->when($partner !== null, fn ($inner) => $inner->orWhere('partner_id', $partner->getKey())))
            ->distinct()->pluck('currency_code')->all();
        $codes = array_values(array_unique(array_filter([...$codes, $also])));
        sort($codes);

        return $codes;
    }

    /** The partner's own list price only (null: its clients see the platform's). */
    public function partnerListPrice(Partner $partner, string $module, string $currency): ?int
    {
        return $this->find(AddonPrice::LIST, $partner->getKey(), $module, $currency, null)?->amount_minor;
    }

    /**
     * Currencies the platform prices a module in (list prices for everyone).
     *
     * @return list<string>
     */
    public function platformCurrencies(string $module): array
    {
        return AddonPrice::query()
            ->whereNull('partner_id')
            ->where('kind', AddonPrice::LIST)
            ->where('module_key', $module)
            ->distinct()
            ->orderBy('currency_code')
            ->pluck('currency_code')
            ->all();
    }

    /**
     * A new price from a day on. null amount (a partner's list price only):
     * the partner stops using its own price and its clients pay the platform's.
     */
    public function set(?Partner $partner, string $kind, string $module, string $currency, ?int $amountMinor, CarbonImmutable $from, ?User $actor = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($partner, $kind, $module, $currency, $amountMinor, $from, $actor, $reason) {
            $old = $this->find($kind, $partner?->getKey(), $module, $currency, null)?->amount_minor;

            if ($amountMinor === null) {
                // Back to the platform's price: the partner's rows go (prices are only replaced, never edited).
                AddonPrice::query()->where('partner_id', $partner?->getKey())->where('kind', $kind)
                    ->where('module_key', $module)->where('currency_code', $currency)->delete();
            } else {
                // A second change the same day replaces that day's price (the audit log keeps both).
                AddonPrice::query()->where('partner_id', $partner?->getKey())->where('kind', $kind)->where('module_key', $module)
                    ->where('currency_code', $currency)->whereDate('effective_from', $from->toDateString())->delete();
                $price = new AddonPrice([
                    'module_key' => $module,
                    'kind' => $kind,
                    'currency_code' => $currency,
                    'amount_minor' => $amountMinor,
                    'effective_from' => $from->toDateString(),
                ]);
                $price->forceFill(['partner_id' => $partner?->getKey(), 'created_by' => $actor?->getKey()])->save();
            }

            $this->audit->record(
                action: 'addons.price_set',
                old: ['amount_minor' => $old],
                new: ['module' => $module, 'kind' => $kind, 'currency' => $currency, 'amount_minor' => $amountMinor, 'effective_from' => $from->toDateString()],
                reason: $reason,
                actor: $actor,
                partnerId: $partner?->getKey(),
            );
        });
    }

    /**
     * Record the data file's prices as the platform defaults. A price that
     * already applies is left alone; a changed one starts today.
     *
     * @param  list<array{module: string, currency: string, list_minor: int, wholesale_minor: int}>  $entries
     * @return int Rows added.
     */
    public function syncDefaults(array $entries): int
    {
        $added = 0;

        foreach ($entries as $entry) {
            foreach ([AddonPrice::LIST => $entry['list_minor'], AddonPrice::WHOLESALE => $entry['wholesale_minor']] as $kind => $amount) {
                $current = $this->find($kind, null, $entry['module'], $entry['currency'], null);
                if ($current !== null && $current->amount_minor === $amount) {
                    continue;
                }

                $from = $current === null ? CarbonImmutable::create(2000, 1, 1) : CarbonImmutable::today();
                $this->set(null, $kind, $entry['module'], $entry['currency'], $amount, $from, reason: 'Default add-on prices (data file)');
                $added++;
            }
        }

        return $added;
    }

    private function find(string $kind, ?string $partnerId, string $module, string $currency, ?CarbonImmutable $on): ?AddonPrice
    {
        return AddonPrice::query()
            ->where('partner_id', $partnerId)
            ->where('kind', $kind)
            ->where('module_key', $module)
            ->where('currency_code', $currency)
            ->when($on !== null, fn ($query) => $query->whereDate('effective_from', '<=', $on->toDateString()))
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->first();
    }
}
