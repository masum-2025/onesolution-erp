<?php

namespace App\Platform\Tenancy\Enums;

enum PartnerUserRole: string
{
    case Owner = 'owner';
    case Sales = 'sales';
    case Support = 'support';
    case Billing = 'billing';
}
