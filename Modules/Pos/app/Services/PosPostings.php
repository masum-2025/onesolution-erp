<?php

namespace Modules\Pos\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Accounting\Ledger\LedgerEntry;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Services\Ledger;

/**
 * Sales into the books, only where the company keeps them (Accounting on),
 * through Accounting's public Ledger; the op id makes a posting happen once.
 */
class PosPostings
{
    /** Where each payment method goes in the books. */
    public const METHOD_KEYS = ['cash' => 'pos.cash', 'card' => 'pos.card', 'mobile' => 'pos.mobile'];

    public function __construct(private ModuleResolver $modules) {}

    /**
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
            sourceModule: 'pos',
            sourceType: $sourceType,
            sourceId: $sourceId,
            currency: $currency,
            lines: $lines,
        ))->id;
    }
}
