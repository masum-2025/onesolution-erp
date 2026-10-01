<?php

namespace Modules\Hrm\Enums;

enum EmployeeStatus: string
{
    case Probation = 'probation';
    case Active = 'active';
    /** Notice given; still employed until exits_on. */
    case OnNotice = 'on_notice';
    case Exited = 'exited';

    public function isEmployed(): bool
    {
        return $this !== self::Exited;
    }
}
