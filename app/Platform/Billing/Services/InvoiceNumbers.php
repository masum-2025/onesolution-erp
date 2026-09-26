<?php

namespace App\Platform\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free document numbers, one series per type and year:
 * INV-2026-000001. The series row is locked for the rest of the caller's
 * transaction, so a number is used only when its invoice is saved too.
 */
class InvoiceNumbers
{
    public function next(string $type, CarbonImmutable $at): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Invoice numbers are taken inside the transaction that saves the invoice.');
        }

        $prefix = config("billing.series.{$type}") ?? throw new LogicException("No number series for [{$type}].");
        $series = "{$prefix}-{$at->year}";

        DB::table('invoice_sequences')->insertOrIgnore(['series' => $series, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $last = (int) DB::table('invoice_sequences')->where('series', $series)->lockForUpdate()->value('last_number');
        DB::table('invoice_sequences')->where('series', $series)->update(['last_number' => $last + 1, 'updated_at' => now()]);

        return sprintf('%s-%06d', $series, $last + 1);
    }
}
