<?php

namespace App\Platform\Meta\Console;

use App\Platform\Meta\MetaApps;
use Illuminate\Console\Command;

/** Applies Meta app changes whose single-approver wait is over. */
class ApplyMetaAppChanges extends Command
{
    protected $signature = 'meta:apply-app-changes';

    protected $description = 'Apply partners\' Meta app changes whose waiting time is over';

    public function handle(MetaApps $apps): int
    {
        $this->info(count($apps->applyDue()).' Meta app change(s) applied.');

        return self::SUCCESS;
    }
}
