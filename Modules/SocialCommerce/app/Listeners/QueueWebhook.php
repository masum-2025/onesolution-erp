<?php

namespace Modules\SocialCommerce\Listeners;

use App\Platform\Meta\Events\MetaWebhookReceived;
use Modules\SocialCommerce\Jobs\HandleWebhook;

/** Meta's webhook events go to the queue at once (Meta wants its answer in seconds). */
class QueueWebhook
{
    public function handle(MetaWebhookReceived $event): void
    {
        HandleWebhook::dispatch($event->metaAppId, $event->payload);
    }
}
