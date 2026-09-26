<?php

namespace App\Platform\SupportAccess\Enums;

enum GrantStatus: string
{
    /** Waiting for a client admin. */
    case Pending = 'pending';

    /** Approved (by a person or the client's auto-approval rule); usable until expires_at. */
    case Approved = 'approved';

    case Rejected = 'rejected';

    /** Ended early by the client or the requester. */
    case Revoked = 'revoked';

    /** Ended by time. */
    case Expired = 'expired';
}
