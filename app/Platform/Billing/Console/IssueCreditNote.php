<?php

namespace App\Platform\Billing\Console;

use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Services\CreditNotes;
use App\Platform\Tenancy\Exceptions\TenancyException;
use Illuminate\Console\Command;

/**
 * Correct an invoice with a credit note (amount before tax, minor units).
 *
 *   php artisan billing:credit INV-2026-000012 --amount=150000 --reason="Two weeks of downtime"
 *   php artisan billing:credit INV-2026-000012 --full --reason="Billed in error"
 */
class IssueCreditNote extends Command
{
    protected $signature = 'billing:credit
        {number : Invoice number}
        {--amount= : Amount before tax, in minor units}
        {--full : Credit everything not yet credited}
        {--reason= : Why (required, printed on the credit note)}';

    protected $description = 'Issue a credit note against an invoice';

    public function handle(CreditNotes $credits): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 5) {
            $this->error('Give a --reason of at least 5 characters.');

            return self::INVALID;
        }

        $invoice = Invoice::query()->where('number', $this->argument('number'))->first();
        if ($invoice === null) {
            $this->error('No invoice with that number.');

            return self::INVALID;
        }

        $amount = $this->option('full') ? (string) $credits->remaining($invoice) : (string) $this->option('amount');
        if (! ctype_digit($amount)) {
            $this->error('Give --amount as a whole number of minor units, or --full.');

            return self::INVALID;
        }

        try {
            $note = $credits->issue($invoice, (int) $amount, $reason);
        } catch (TenancyException $exception) {
            $this->error($exception->userMessage());

            return self::FAILURE;
        }

        $this->info("{$note->number} credits {$note->total_minor} {$note->currency_code} on {$invoice->number}.");

        return self::SUCCESS;
    }
}
