<?php

namespace App\Platform\Security\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A security event was written to the security log (Phase 9-2). Carries the
 * same cleaned context (ids, codes, the request's origin), never secrets.
 */
class SecurityEventRecorded
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $event,
        public array $context,
        public string $level,
    ) {}
}
