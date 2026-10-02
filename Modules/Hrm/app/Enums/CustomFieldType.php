<?php

namespace Modules\Hrm\Enums;

/** What kind of value an extra employee field holds. */
enum CustomFieldType: string
{
    case Text = 'text';
    /** Kept as a plain decimal string ("12", "3.5"), never a float. */
    case Number = 'number';
    case Date = 'date';
    case Choice = 'choice';
    case YesNo = 'yes_no';
}
