<?php

namespace App\Platform\Meta\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A partner's Meta app got new credentials. With replaced (a different app
 * id), tokens signed in through the old app no longer work: modules holding
 * pages of this app ask for a new sign-in.
 */
class MetaAppChanged
{
    use Dispatchable;

    public function __construct(public readonly string $metaAppId, public readonly bool $replaced) {}
}
