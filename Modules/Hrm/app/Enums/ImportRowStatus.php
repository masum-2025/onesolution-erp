<?php

namespace Modules\Hrm\Enums;

enum ImportRowStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Imported = 'imported';
    /** Valid when checked, refused when imported (someone changed things meanwhile). */
    case Failed = 'failed';
    /** Invalid rows left out when the import ran. */
    case Skipped = 'skipped';
}
