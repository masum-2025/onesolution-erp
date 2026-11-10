<?php

namespace Modules\PatientBilling\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Accounting\Ledger\LedgerEntry;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Ledger;
use Modules\Accounting\Services\TaxCodes;

/**
 * Bills and money into the books, only where the company keeps them
 * (Accounting on and set up), through Accounting's public Ledger; the op id
 * makes a posting happen once. Without books, billing works the same and
 * nothing is posted.
 */
class BillingPostings
{
    /** Where each way of paying goes in the books. */
    public const METHOD_KEYS = [
        'cash' => 'patient_billing.cash',
        'card' => 'patient_billing.card',
        'mobile' => 'patient_billing.mobile',
        'advance' => 'patient_billing.advance',
    ];

    public function __construct(private ModuleResolver $modules) {}

    public function keepsBooks(Organization $company): bool
    {
        return $this->modules->isEnabled('accounting', $company) && app(Books::class)->isSetUp($company);
    }

    /**
     * Sales tax rates (basis points) of the tax codes asked for; none without books.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    public function taxRates(Organization $company, array $ids): array
    {
        return $ids === [] || ! $this->keepsBooks($company) ? [] : app(TaxCodes::class)->salesRates($company, $ids);
    }

    /**
     * @param  list<LedgerLine>  $lines
     */
    public function post(Organization $company, string $opId, CarbonImmutable $date, string $narration, string $sourceType, string $sourceId, string $currency, array $lines): ?string
    {
        $lines = array_values(array_filter($lines, fn (LedgerLine $line) => $line->debitMinor + $line->creditMinor > 0));
        if ($lines === [] || ! $this->keepsBooks($company)) {
            return null;
        }

        return app(Ledger::class)->post($company, new LedgerEntry(
            opId: $opId,
            entryDate: $date->toDateString(),
            narration: $narration,
            sourceModule: 'patient_billing',
            sourceType: $sourceType,
            sourceId: $sourceId,
            currency: $currency,
            lines: $lines,
        ))->id;
    }
}
