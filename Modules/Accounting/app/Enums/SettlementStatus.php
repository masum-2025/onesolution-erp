<?php

namespace Modules\Accounting\Enums;

enum SettlementStatus: string
{
    /** Above the approval amount: waits for a second person (nothing in the books yet). */
    case PendingApproval = 'pending_approval';
    /** Refused by the approver; never entered the books. */
    case Rejected = 'rejected';
    case Posted = 'posted';
    /** Cancelled after posting: its journal is reversed and its allocations released. */
    case Void = 'void';
}
