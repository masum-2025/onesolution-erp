<?php

namespace App\Platform\Tenancy\Enums;

enum MembershipType: string
{
    case Owner = 'owner';
    case Staff = 'staff';
    case Portal = 'portal';
}
