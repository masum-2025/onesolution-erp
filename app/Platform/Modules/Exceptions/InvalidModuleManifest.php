<?php

namespace App\Platform\Modules\Exceptions;

use LogicException;

/**
 * A developer error in a module manifest. Raised while loading the registry,
 * so a broken manifest fails deploys and tests instead of reaching users.
 */
class InvalidModuleManifest extends LogicException
{
    public static function because(string $module, string $problem): self
    {
        return new self("Module manifest [{$module}] is invalid: {$problem}");
    }
}
