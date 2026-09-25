<?php

namespace App\Platform\Tenancy\Enums;

enum BillingMode: string
{
    case Direct = 'direct';
    case Wholesale = 'wholesale';
    case RevenueShare = 'revenue_share';
}
