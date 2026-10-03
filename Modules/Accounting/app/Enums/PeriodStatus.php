<?php

namespace Modules\Accounting\Enums;

enum PeriodStatus: string
{
    case Open = 'open';
    /** Nothing is posted into a closed period until someone reopens it (with a reason). */
    case Closed = 'closed';
}
