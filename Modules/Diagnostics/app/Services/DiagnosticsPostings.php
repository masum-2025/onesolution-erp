<?php

namespace Modules\Diagnostics\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Accounting\Ledger\LedgerEntry;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Ledger;

/**
 * Commission into the books, only where the company keeps them (Accounting
 * on and set up), through Accounting's public Ledger; the op id makes a
 * posting happen once. Without books, commission works the same and nothing
 * is posted.
 */
class DiagnosticsPostings
{
    /** Where each way of paying a doctor comes from in the books. */
    public const METHOD_KEYS = [
        'cash' => 'diagnostics.cash',
        'bank' => 'diagnostics.bank',
        'mobile' => 'diagnostics.mobile',
    ];

    public function __construct(private ModuleResolver $modules) {}

    public function keepsBooks(Organization $company): bool
    {
        return $this->modules->isEnabled('accounting', $company) && class_exists(Books::class) && app(Books::class)->isSetUp($company);
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
            sourceModule: 'diagnostics',
            sourceType: $sourceType,
            sourceId: $sourceId,
            currency: $currency,
            lines: $lines,
        ))->id;
    }
}
