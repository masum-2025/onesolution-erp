<?php

namespace Modules\Accounting\Ledger;

/**
 * What another module asks Accounting to post (Modules\Accounting\Services\Ledger).
 *
 * - opId: the caller's idempotency key; sending the same entry twice posts it once.
 * - source: the caller's own record, e.g. ("payroll", "payslip_run", "01J...").
 * - currency: the amounts' currency; must be the company's book currency for now.
 */
final readonly class LedgerEntry
{
    /**
     * @param  list<LedgerLine>  $lines
     */
    public function __construct(
        public string $opId,
        public string $entryDate,
        public string $narration,
        public string $sourceModule,
        public string $sourceType,
        public string $sourceId,
        public string $currency,
        public array $lines,
    ) {}
}
