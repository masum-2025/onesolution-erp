<?php

namespace App\Platform\Billing\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Money totals kept apart per currency (never added across currencies),
 * with the query builder only, so they run the same on every database.
 */
final class CurrencyTotals
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, array{total: int, count: int}>
     */
    public static function of(Builder $query, string $column): array
    {
        $totals = [];

        foreach ((clone $query)->distinct()->orderBy('currency_code')->pluck('currency_code') as $currency) {
            $rows = (clone $query)->where('currency_code', $currency);
            $totals[$currency] = ['total' => (int) (clone $rows)->sum($column), 'count' => (clone $rows)->count()];
        }

        return $totals;
    }
}
