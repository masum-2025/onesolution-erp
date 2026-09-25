<?php

namespace App\Platform\Modules\Enums;

enum PurgeStatus: string
{
    case Pending = 'pending';
    case Cancelled = 'cancelled';
    case Done = 'done';
}
