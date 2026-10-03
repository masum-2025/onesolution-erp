<?php

namespace Modules\Accounting\Ledger;

/**
 * One debit or credit of a LedgerEntry, to a posting key the calling module
 * declares in its manifest (ledger_accounts, e.g. "payroll.salary_expense").
 * Each company maps its keys to its own accounts, so callers never name an
 * account. Amounts are minor units; exactly one side is above zero.
 */
final readonly class LedgerLine
{
    public function __construct(
        public string $postingKey,
        public int $debitMinor = 0,
        public int $creditMinor = 0,
        public ?string $costCentreId = null,
        public ?string $memo = null,
    ) {}

    public static function debit(string $postingKey, int $amountMinor, ?string $costCentreId = null, ?string $memo = null): self
    {
        return new self($postingKey, debitMinor: $amountMinor, costCentreId: $costCentreId, memo: $memo);
    }

    public static function credit(string $postingKey, int $amountMinor, ?string $costCentreId = null, ?string $memo = null): self
    {
        return new self($postingKey, creditMinor: $amountMinor, costCentreId: $costCentreId, memo: $memo);
    }
}
