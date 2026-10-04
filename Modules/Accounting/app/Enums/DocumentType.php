<?php

namespace Modules\Accounting\Enums;

/**
 * Documents of receivables (sales side) and payables (purchase side). An
 * invoice or bill is owed; a credit note or vendor credit takes it back.
 */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Bill = 'bill';
    case VendorCredit = 'vendor_credit';

    public function isSales(): bool
    {
        return in_array($this, [self::Invoice, self::CreditNote], true);
    }

    /** A credit reduces what is owed instead of adding to it. */
    public function isCredit(): bool
    {
        return in_array($this, [self::CreditNote, self::VendorCredit], true);
    }

    /** The document a credit of this side is applied to. */
    public function owedType(): self
    {
        return $this->isSales() ? self::Invoice : self::Bill;
    }

    /** Account types its lines may use: income for sales, expenses or assets (equipment, stock) for purchases. */
    public function lineAccountTypes(): array
    {
        return $this->isSales() ? [AccountType::Income] : [AccountType::Expense, AccountType::Asset];
    }

    /** The posting key of the party account: what customers owe, or what is owed to vendors. */
    public function partyPostingKey(): string
    {
        return $this->isSales() ? 'accounting.receivable' : 'accounting.payable';
    }
}
