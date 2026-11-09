<?php

namespace Modules\SocialCommerce\Listeners;

use App\Platform\Meta\Events\MetaAppChanged;
use App\Platform\Meta\Events\MetaPersonRemovedApp;
use Modules\SocialCommerce\Services\Pages;

/**
 * Meta app changes and Meta's callbacks, for the pages they concern: a
 * replaced app or a person who removed it → pages wait for a new sign-in;
 * a data deletion request → their tokens are forgotten.
 */
class StopPagesOfMetaApp
{
    public function __construct(private Pages $pages) {}

    public function handle(MetaAppChanged|MetaPersonRemovedApp $event): void
    {
        if ($event instanceof MetaAppChanged) {
            if ($event->replaced) {
                $this->pages->stopForApp($event->metaAppId, null, false);
            }

            return;
        }

        $count = $this->pages->stopForApp($event->metaAppId, $event->metaPersonId, $event->deleted);
        if ($event->deleted) {
            $event->removed += $count;
        }
    }
}
