<?php

namespace Modules\Accounting\Enums;

enum JournalStatus: string
{
    /** Being written; may be unbalanced, changed or removed. */
    case Draft = 'draft';
    /** Above the approval amount: waits for a second person. */
    case PendingApproval = 'pending_approval';
    /** In the books for good: never changed, only reversed. */
    case Posted = 'posted';
    /** Sent back by the approver; may be changed and sent again. */
    case Rejected = 'rejected';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }
}
