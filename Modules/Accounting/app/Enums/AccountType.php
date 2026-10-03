<?php

namespace Modules\Accounting\Enums;

/**
 * The five kinds of account. Assets and expenses grow with debits; the others
 * with credits, so their balance is shown as credit minus debit.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function growsWithDebit(): bool
    {
        return in_array($this, [self::Asset, self::Expense], true);
    }

    /** The balance in this account's own direction (positive = normal). */
    public function balance(int $debit, int $credit): int
    {
        return $this->growsWithDebit() ? $debit - $credit : $credit - $debit;
    }

    /** Income and expenses make the profit of a period; the others carry over. */
    public function isProfitAndLoss(): bool
    {
        return in_array($this, [self::Income, self::Expense], true);
    }
}
