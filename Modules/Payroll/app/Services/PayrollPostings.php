<?php

namespace Modules\Payroll\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Accounting\Ledger\LedgerEntry;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Services\Ledger;

/**
 * Payroll into the books, only where the company keeps them (Accounting
 * on), through Accounting's public Ledger. The op id makes a posting happen
 * once however often it is tried.
 */
class PayrollPostings
{
    public function __construct(private ModuleResolver $modules) {}

    /**
     * The journal id, or null when nothing was posted.
     *
     * @param  list<LedgerLine>  $lines
     */
    public function post(Organization $company, string $opId, CarbonImmutable $date, string $narration, string $sourceType, string $sourceId, string $currency, array $lines): ?string
    {
        $lines = array_values(array_filter($lines, fn (LedgerLine $line) => $line->debitMinor + $line->creditMinor > 0));
        if ($lines === [] || ! $this->modules->isEnabled('accounting', $company)) {
            return null;
        }

        return app(Ledger::class)->post($company, new LedgerEntry(
            opId: $opId,
            entryDate: $date->toDateString(),
            narration: $narration,
            sourceModule: 'payroll',
            sourceType: $sourceType,
            sourceId: $sourceId,
            currency: $currency,
            lines: $lines,
        ))->id;
    }
}
