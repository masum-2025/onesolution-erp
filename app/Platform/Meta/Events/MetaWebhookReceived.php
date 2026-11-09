<?php

namespace App\Platform\Meta\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Meta sent an event for one app (comments, messages), already checked
 * against the app's signature. Modules that hold the pages pick out what
 * is theirs (by page id); the payload is Meta's, as sent.
 */
class MetaWebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly string $metaAppId, public readonly array $payload) {}
}
