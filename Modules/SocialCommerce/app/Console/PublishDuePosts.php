<?php

namespace Modules\SocialCommerce\Console;

use Illuminate\Console\Command;
use Modules\SocialCommerce\Services\Posts;

/** Every minute: posts whose time has come go to the queue; posts stuck while publishing are marked failed. */
class PublishDuePosts extends Command
{
    protected $signature = 'social:publish-due';

    protected $description = 'Send Facebook posts whose time has come';

    public function handle(Posts $posts): int
    {
        $this->info($posts->sendDue().' post(s) sent to the queue.');

        return self::SUCCESS;
    }
}
