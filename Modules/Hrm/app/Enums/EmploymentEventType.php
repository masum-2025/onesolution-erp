<?php

namespace Modules\Hrm\Enums;

enum EmploymentEventType: string
{
    case Hired = 'hired';
    case Confirmed = 'confirmed';
    case Transferred = 'transferred';
    case Promoted = 'promoted';
    case NoticeGiven = 'notice_given';
    case Exited = 'exited';
    case Rehired = 'rehired';
}
