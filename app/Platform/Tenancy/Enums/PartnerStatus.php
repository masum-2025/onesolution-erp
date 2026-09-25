<?php

namespace App\Platform\Tenancy\Enums;

enum PartnerStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
