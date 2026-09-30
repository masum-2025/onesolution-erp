<?php

namespace App\Platform\Tenancy\Databases;

enum PlacementStatus: string
{
    case Active = 'active';

    /** Data is being copied to another database: business data is read-only. */
    case Moving = 'moving';
}
