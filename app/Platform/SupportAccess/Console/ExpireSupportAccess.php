<?php

namespace App\Platform\SupportAccess\Console;

use App\Platform\SupportAccess\Services\SupportAccessService;
use Illuminate\Console\Command;

/**
 * Marks support grants whose time ran out as expired and writes that to the
 * client's audit log. Access already stops at the expiry time (the context
 * check refuses it); this makes the ending visible. Scheduled every minute.
 */
class ExpireSupportAccess extends Command
{
    protected $signature = 'support:expire';

    protected $description = 'Close support access whose time has run out';

    public function handle(SupportAccessService $support): int
    {
        $this->info($support->expireDue().' support grants expired.');

        return self::SUCCESS;
    }
}
