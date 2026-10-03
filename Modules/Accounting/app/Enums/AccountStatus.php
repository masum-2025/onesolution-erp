<?php

namespace Modules\Accounting\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    /** Kept with its history; nothing new is posted to it. */
    case Archived = 'archived';
}
