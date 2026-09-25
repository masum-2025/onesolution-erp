<?php

namespace App\Platform\Rules\Enums;

enum RuleValueStatus: string
{
    case Active = 'active';
    case PendingApproval = 'pending_approval';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
