<?php

namespace Modules\Accounting\Enums;

/** Money received from a customer, or paid to a vendor. */
enum SettlementType: string
{
    case Receipt = 'receipt';
    case Payment = 'payment';

    /** The documents it pays. */
    public function documentType(): DocumentType
    {
        return $this === self::Receipt ? DocumentType::Invoice : DocumentType::Bill;
    }

    public function partyPostingKey(): string
    {
        return $this->documentType()->partyPostingKey();
    }
}
