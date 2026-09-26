<?php

namespace App\Platform\Billing\Console;

use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Services\InvoicePayments;
use App\Platform\Tenancy\Exceptions\TenancyException;
use Illuminate\Console\Command;

/**
 * Record a payment received (no payment gateway yet).
 *
 *   php artisan billing:mark-paid INV-2026-000012 --reference="Bank ref 88213"
 */
class MarkInvoicePaid extends Command
{
    protected $signature = 'billing:mark-paid
        {number : Invoice number}
        {--reference= : Bank or payment reference (required)}';

    protected $description = 'Record that an invoice was paid';

    public function handle(InvoicePayments $payments): int
    {
        $reference = trim((string) $this->option('reference'));
        if (mb_strlen($reference) < 3) {
            $this->error('Give a --reference of at least 3 characters.');

            return self::INVALID;
        }

        $invoice = Invoice::query()->where('number', $this->argument('number'))->first();
        if ($invoice === null) {
            $this->error('No invoice with that number.');

            return self::INVALID;
        }

        try {
            $payments->markPaid($invoice, $reference);
        } catch (TenancyException $exception) {
            $this->error($exception->userMessage());

            return self::FAILURE;
        }

        $this->info("{$invoice->number} is paid.");

        return self::SUCCESS;
    }
}
