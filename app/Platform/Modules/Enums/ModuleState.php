<?php

namespace App\Platform\Modules\Enums;

enum ModuleState: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Inherit = 'inherit';
}
