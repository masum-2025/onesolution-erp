<?php

namespace Modules\Accounting\Enums;

enum DocumentStatus: string
{
    /** Being written; may change or be removed. */
    case Draft = 'draft';
    /** Above the approval amount: waits for a second person. */
    case PendingApproval = 'pending_approval';
    /** Sent back by the approver; may change and be sent again. */
    case Rejected = 'rejected';
    /** In the books, nothing paid (or, for a credit, nothing used) yet. */
    case Posted = 'posted';
    case PartlyPaid = 'partly_paid';
    case Paid = 'paid';
    /** Cancelled after posting: its journal is reversed, it stays for the record. */
    case Void = 'void';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /** In the books and not cancelled. */
    public function isPosted(): bool
    {
        return in_array($this, [self::Posted, self::PartlyPaid, self::Paid], true);
    }

    /** The status of a posted document from how much of it is settled. */
    public static function settled(int $total, int $allocated): self
    {
        return match (true) {
            $allocated <= 0 => self::Posted,
            $allocated < $total => self::PartlyPaid,
            default => self::Paid,
        };
    }
}
