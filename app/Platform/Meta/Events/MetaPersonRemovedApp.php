<?php

namespace App\Platform\Meta\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Meta told us a person removed the app (deauthorize) or asked for their
 * data to go (data deletion). Modules holding pages that person connected
 * through this app stop using them; with deleted, they forget the tokens
 * and count what they removed in removed.
 */
class MetaPersonRemovedApp
{
    use Dispatchable;

    /** Pages removed by the listeners (data deletion). */
    public int $removed = 0;

    public function __construct(
        public readonly string $metaAppId,
        public readonly string $metaPersonId,
        public readonly bool $deleted,
    ) {}
}
