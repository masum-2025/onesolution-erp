<?php

namespace Modules\Pos\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Pos\Models\Payment;
use Modules\Pos\Models\Sale;
use Modules\Pos\Models\SaleLine;

/**
 * Takings at the counters a reader sees between two days (the company's
 * own days and hours): totals, and the same split by day, hour, cashier,
 * payment method and item. Returns count against what they return. Sums are
 * made here, not in SQL, so every database gives the same answer.
 */
class PosReports
{
    /** Longest range one report covers. */
    public const MAX_DAYS = 366;

    /** Items listed, best sellers first. */
    public const TOP_ITEMS = 100;

    public function __construct(private Tills $tills) {}

    /**
     * @param  list<string>  $registers
     * @return array<string, mixed>
     */
    public function takings(Organization $company, array $registers, string $from, string $to): array
    {
        $zone = $this->tills->timezone($company);
        $start = CarbonImmutable::parse($from, $zone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $zone)->endOfDay()->utc();

        $totals = ['sales' => 0, 'sales_count' => 0, 'returns' => 0, 'returns_count' => 0, 'net' => 0, 'tax' => 0, 'discount' => 0, 'cost' => 0];
        $days = [];
        $hours = array_fill(0, 24, ['amount' => 0, 'count' => 0]);
        $cashiers = [];
        $signs = [];
        foreach ($this->tills->query(Sale::class, $company)->whereIn('register_id', $registers)->whereBetween('sold_at', [$start, $end])
            ->select(['id', 'kind', 'sold_at', 'total_minor', 'tax_minor', 'discount_minor', 'change_minor', 'cost_minor', 'created_by'])->cursor() as $sale) {
            $sign = $sale->kind === 'return' ? -1 : 1;
            $signs[$sale->getKey()] = [$sign, (int) $sale->change_minor];
            $amount = $sign * (int) $sale->total_minor;
            $totals[$sign > 0 ? 'sales' : 'returns'] += (int) $sale->total_minor;
            $totals[$sign > 0 ? 'sales_count' : 'returns_count']++;
            $totals['net'] += $amount;
            $totals['tax'] += $sign * (int) $sale->tax_minor;
            $totals['discount'] += $sign * (int) $sale->discount_minor;
            $totals['cost'] += $sign * (int) $sale->cost_minor;

            $local = CarbonImmutable::parse($sale->sold_at)->setTimezone($zone);
            $day = $local->toDateString();
            $days[$day] ??= ['date' => $day, 'amount' => 0, 'count' => 0];
            $days[$day]['amount'] += $amount;
            $cashiers[$sale->created_by] ??= ['user_id' => $sale->created_by, 'amount' => 0, 'count' => 0, 'returns' => 0];
            $cashiers[$sale->created_by]['amount'] += $amount;
            if ($sign > 0) {
                $days[$day]['count']++;
                $hours[$local->hour]['amount'] += $amount;
                $hours[$local->hour]['count']++;
                $cashiers[$sale->created_by]['count']++;
            } else {
                $hours[$local->hour]['amount'] += $amount;
                $cashiers[$sale->created_by]['returns']++;
            }
        }
        // Margin before VAT: what the goods sold for, less VAT, less what they cost.
        $totals['margin'] = $totals['net'] - $totals['tax'] - $totals['cost'];
        $totals['average'] = $totals['sales_count'] > 0 ? intdiv($totals['sales'], $totals['sales_count']) : 0;

        $methods = [];
        $items = [];
        foreach (array_chunk(array_keys($signs), 500) as $ids) {
            foreach ($this->tills->query(Payment::class, $company)->whereIn('sale_id', $ids)->select(['sale_id', 'method', 'amount_minor'])->cursor() as $payment) {
                [$sign, $change] = $signs[$payment->sale_id];
                // Cash takings are what stayed in the drawer: paid less the change given (once per sale).
                $kept = (int) $payment->amount_minor;
                if ($payment->method === 'cash' && $sign > 0 && $change > 0) {
                    $kept -= $change;
                    $signs[$payment->sale_id][1] = 0;
                }
                $methods[$payment->method] = ($methods[$payment->method] ?? 0) + $sign * $kept;
            }
            foreach ($this->tills->query(SaleLine::class, $company)->whereIn('sale_id', $ids)->get(['sale_id', 'item_id', 'sku', 'name', 'quantity_milli', 'net_minor', 'total_minor', 'cost_minor']) as $line) {
                $sign = $signs[$line->sale_id][0];
                $items[$line->item_id] ??= ['item_id' => $line->item_id, 'sku' => $line->sku, 'name' => $line->name, 'quantity_milli' => 0, 'amount' => 0, 'net' => 0, 'cost' => 0];
                $items[$line->item_id]['quantity_milli'] += $sign * $line->quantity_milli;
                $items[$line->item_id]['amount'] += $sign * $line->total_minor;
                // Before VAT, for the margin.
                $items[$line->item_id]['net'] += $sign * $line->net_minor;
                $items[$line->item_id]['cost'] += $sign * $line->cost_minor;
            }
        }
        usort($items, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);
        $names = User::query()->whereKey(array_keys($cashiers))->pluck('name', 'id');
        ksort($days);
        uasort($cashiers, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);

        return [
            'from' => $from, 'to' => $to, 'currency' => $this->tills->currency($company), 'timezone' => $zone,
            'totals' => $totals,
            'days' => array_values($days),
            'hours' => array_map(fn (int $hour, array $row) => ['hour' => $hour, ...$row], array_keys($hours), $hours),
            'cashiers' => array_values(array_map(fn (array $row) => [...$row, 'name' => $names[$row['user_id']] ?? null], $cashiers)),
            'methods' => array_map(fn (string $method, int $amount) => ['method' => $method, 'amount' => $amount], array_keys($methods), $methods),
            'items' => array_slice($items, 0, self::TOP_ITEMS),
        ];
    }
}
