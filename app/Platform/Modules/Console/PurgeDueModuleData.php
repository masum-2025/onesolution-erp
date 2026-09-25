<?php

namespace App\Platform\Modules\Console;

use App\Platform\Modules\Services\ModulePurgeService;
use Illuminate\Console\Command;

class PurgeDueModuleData extends Command
{
    protected $signature = 'modules:purge-due';

    protected $description = 'Execute confirmed module data purges whose waiting period has passed';

    public function handle(ModulePurgeService $purges): int
    {
        $count = $purges->executeDue();

        $this->info("Processed {$count} purge request(s).");

        return self::SUCCESS;
    }
}
