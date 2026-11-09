<?php

namespace Modules\SocialCommerce\Console;

use Illuminate\Console\Command;
use Modules\SocialCommerce\Services\Conversations;

/** Daily: conversations older than social_commerce.keep_messages_days are forgotten (customers' personal data). */
class PruneConversations extends Command
{
    protected $signature = 'social:prune';

    protected $description = 'Forget Facebook conversations older than the days kept';

    public function handle(Conversations $conversations): int
    {
        $this->info($conversations->prune().' conversation(s) forgotten.');

        return self::SUCCESS;
    }
}
